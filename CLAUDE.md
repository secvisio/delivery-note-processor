# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project purpose

A Laravel 12 service that watches an inbox directory for scanned delivery notes (PDFs/images), runs OCR on them, asks an
LLM (via the `neuron-ai` agent) to extract a delivery-note id and company name, and copies the file into a target
directory under a generated, normalized filename.

## Commands

- `composer dev` — runs server, queue worker, log tail (`pail`), and Vite concurrently. The all-in-one local entry
  point.
- `composer test` — clears config and runs `php artisan test` (Pest 4 + PHPUnit, suites `Unit` and `Feature`).
- Single test: `php artisan test --filter=DeliveryNoteProcessorServiceTest` (or any class/method name).
- `php artisan queue:listen --tries=1` — process queued jobs locally without restarting on code changes; use Horizon (
  `php artisan horizon`) when running against the Redis queue connection.
- `php artisan app:process-file {filename}` — manually inject a file already sitting in the source folder into the
  pipeline.
- `npm run dev` / `npm run build` — Vite (Tailwind 4 + Livewire frontend).

## Runtime topology

The pipeline has **three entry points** that all funnel into the same processor service. Knowing this matters when
debugging — the same code path is reachable in three ways:

1. **HTTP upload** — `POST /upload` → `UploadController` writes to `source/` on the `delivery_notes` disk. Does **not**
   dispatch a job; arrival is detected by the watcher.
2. **Filesystem watcher** — `docker/php/watch-delivery.sh` runs `inotifywait` on the source folder and pipes new
   filenames to `php artisan app:process-file`. This is what kicks off processing in production.
3. **Console** — `app:process-file {filename}` directly calls `DeliveryNoteProcessorService::fileArrived()`, which
   creates a `Process` row and dispatches `ProcessDeliveryNoteJob` to the `default` queue.

The job calls `DeliveryNoteProcessorService::run($process)`, which:

1. If the source is a PDF, converts it to JPG via `FileConverterService` (Spatie `pdf-to-image`, which uses
   ImageMagick — see ImageMagick PDF policy note below).
2. Runs Tesseract OCR on the image (`thiagoalessio/tesseract_ocr`).
3. Sends the OCR text to `DeliveryNoteAgent` (a `neuron-ai` Agent backed by OpenAI Responses API) with a prompt asking
   for a JSON object: `{locale, company:{name, percent}, deliveryNote:{id, percent}, invoice:{id, percent}}`.
4. Compares the LLM's `percent` certainty against `threshold` (default `0.85`). If the delivery-note id passes, the file
   is renamed `{company}_ls_{id}.{ext}` (snake-cased). Otherwise it's named
   `{unknown_company}_{unknown_id}_{timestamp}.{ext}` — invoice-id fallback exists in the code but is **commented out**.
5. Files with low confidence are copied to `unknown_folder` instead of `target_folder`.
6. The `Process` row is updated with all extracted fields, token usage, and timing — this row is what
   `app/Livewire/ProcessLog.php` renders on the dashboard.

## Storage and disks

All file IO goes through the `delivery_notes` Laravel disk (configured in `config/filesystems.php`). The disk root in
production is **outside** `storage/` — `.env.production` points it at `/mnt/laufwerk` with German folder names (
`Lieferscheine`, `Nicht zugeordnet`, `Produktionsaufträge`). In dev the disk root is `storage_path('delivery-notes')`. *
*Always use `Storage::disk(config('delivery_note_processor.delivery_notes_disk'))` and pass relative paths**, not
absolute ones — `FileConverterService` shows the convert-to-absolute-then-back-to-relative dance required for libraries
that need real filesystem paths (Spatie PDF, Tesseract).

Folder names come from `config/delivery_note_processor.php`:

- `source_folder` (where files arrive — also what `inotifywait` watches)
- `target_folder` (where successful renames go)
- `unknown_folder` (low-confidence dump)

## Configuration knobs

- LLM provider/model: `config/neuron.php` → `NEURON_OPENAI_KEY`, `NEURON_OPENAI_MODEL` (production runs `gpt-5-mini`).
- OCR threshold: hardcoded as `protected float $threshold = 0.85` on `DeliveryNoteProcessorService`. There's a setter —
  override via `setThreshold()` if testing.
- OCR text saved to DB is truncated at `maxOcrChatToSave = 16383` (column was `MEDIUMTEXT`-sized). The `Process` model *
  *base64-encodes `ocr_result` on write and decodes on read** via accessor/mutator — don't bypass `$process->ocr_result`
  with raw queries.
- Queue: `redis` in production (Horizon), `sync` in tests (`phpunit.xml`).

## Operational gotcha — ImageMagick PDF policy

The host's ImageMagick policy commonly blocks PDF coders. If PDF→image conversion fails, edit
`/etc/ImageMagick-6/policy.xml` and change `<policy domain="coder" rights="none" pattern="PDF" />` to
`rights="read|write"`. This is the entire content of the project's `README.md`.

## Docker

`docker-compose.yml` brings up `nginx` (172.180.0.2), `php` (172.180.0.3, with supervisord running the queue + watcher),
and `redis` (172.180.0.10) on an **external** network `delivery_note_processor` — create it first (
`docker network create delivery_note_processor`) or compose up will fail.
