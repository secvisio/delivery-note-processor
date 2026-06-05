<?php
declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessDeliveryNoteJob;
use App\Models\Process;
use App\Neuron\DeliveryNoteAgent;
use App\Neuron\ProductionOrderAgent;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use NeuronAI\Agent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Spatie\PdfToImage\Exceptions\PdfDoesNotExist;
use stdClass;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use thiagoalessio\TesseractOCR\TesseractOcrException;
use thiagoalessio\TesseractOCR\UnsuccessfulCommandException;
use Throwable;

class DeliveryNoteProcessorService
{
    private const FIXED_ORIGINALS_PATH = '/mnt/laufwerk/ScannerOriginale';
    private const FIXED_DELIVERY_NOTES_PATH = '/mnt/laufwerk/Lieferscheine';
    private const FIXED_UNKNOWN_PATH = '/mnt/laufwerk/Nicht zugeordnet';
    private const FIXED_PRODUCTION_ORDERS_PATH = '/mnt/laufwerk/Produktionsaufträge';

    /**
     * @var string|null
     */
     protected ?string $sourcePath = null;

    /**
     * @var string|null
     */
     protected ?string $targetPath = null;

    /**
     * @var string
     */
    protected string $agentPrompt = '
            you\'ll get a plain text parsed by an ocr scanner. we do not know the delivery note format. first you need
            to find out the language the delivery note is written in. There are 4 things you need to perform.
            1st: find primarily the delivery note id. this could be probably a numeric id or an alpha-numeric id. in case you do not
            find a delivery note, try to find an invoice id which as well could be probably a numeric id or an
            alpha-numeric id.
            2nd: try to find the company name of the company that sent the delivery note.
            3rd: depending on what you have found calculate how certain you are about your own result.
            1 will be 100% and e.g. 0.1 with be 10%. use up to 2 digits after the dot like e.g. 0.15 for 15%.
            4th: create a json array containing your results. this array will have the following format:

            {"locale": "de", "company": {"name":"[your result]", "percent":"[your calculated certainty]"}, "deliveryNote": {"id": "[your found id]", "percent":"[your calculated certainty]"}, "invoice": {"id": "[your found id]", "percent":"[your calculated certainty]"}}

            set the value to `null` for things you couldn\'t find. do not add any other comments, descriptions or
            whatsoever to the output. Following you\'ll find the content scanned by ocr scanner:

            {{ $ocrContent }}
        ';

    /**
     * Prompt for the production-order extraction agent. Two independent
     * decisions: (1) IS this a production order? (2) which of the two
     * header values could be extracted? Missing values must come back as
     * null — the agent must NOT invent numbers. The processor compares
     * is_production_order against the OCR content; if true, the file is
     * routed to the Produktionsaufträge folder regardless of how many
     * values were extractable.
     *
     * @var string
     */
    protected string $productionOrderPrompt = '
            You will receive plain text parsed by an OCR scanner from a scanned document.

            Decide independently:
              (1) whether the document is a production order ("Produktionsauftrag")
              (2) which of two values, if any, can be reliably extracted

            The two values typically appear near the TOP of the document, inside the first
            header table:
              - Auftrag (Auftragsnummer)
              - Produktion (Produktionsnummer)

            Rules:
              - Prefer values from the first/top header table, not from later sections.
              - Allow minor OCR mistakes (e.g. O vs 0, I vs 1, missing colons or whitespace).
              - Do NOT invent or guess numbers. If a value cannot be confidently extracted, return null.
              - is_production_order can be true even when BOTH values are unreadable, as long
                as the layout/structure/keywords clearly indicate a production order.
              - is_production_order must be false if the document is something else (delivery
                note, invoice, unrelated form).
              - "missing_values" must list "auftragsnummer" and/or "produktion" only when
                is_production_order is true AND that value could not be extracted. If
                is_production_order is false, missing_values must be [].
              - "confidence" is one of "high", "medium", "low". Use "low" when is_production_order
                is false.

            Return a single JSON object with EXACTLY these keys, no markdown, no extra prose:

            {"is_production_order": true|false, "auftragsnummer": "..."|null, "produktion": "..."|null, "missing_values": [...], "confidence": "high"|"medium"|"low", "reason": "..."}

            OCR text follows:

            {{ $ocrContent }}
        ';

    /**
     * @var float
     */
    protected float $threshold = 0.85;

    /**
     * @var int
     */
    protected int $maxOcrChatToSave = 16383;

    /**
     * Hard cap on Tesseract runtime, in seconds. Must be strictly less than
     * ProcessDeliveryNoteJob::$timeout (worker timeout) which itself must be
     * strictly less than the queue's retry_after.
     */
    private const TESSERACT_TIMEOUT_SECONDS = 120;

    /**
     * @var Filesystem
     */
    protected Filesystem $disk;

    public function __construct() {
        $this->disk = Storage::disk(config('delivery_note_processor.delivery_notes_disk'));
    }

    /**
     * @param Process $process
     * @return Process
     * @throws FileNotFoundException
     * @throws PdfDoesNotExist
     * @throws TesseractOcrException
     * @throws Throwable
     */
    public function run(Process $process): Process
    {

        if (!$this->disk->exists($process->source_file_path)) {
            $process->update([
                'status' => 'failed',
                'failed_message' => "Source path '{$process->source_file_path}' does not exist.",
            ]);

            return $process;
        }

        $this->copyToFixedPath(
            $process->source_file_path,
            self::FIXED_ORIGINALS_PATH,
            basename($process->source_file_path)
        );

        // Absolute path of a temporary converted image to clean up after OCR.
        $convertedTempFile = null;

        if ($this->isPdf($process->source_file_path)) {

            try {
                // PDFs are rasterised into a NON-watched temp dir (not `source`,
                // not an output target) and read from there for OCR only.
                $fileConverter = new FileConverterService(FileConverterService::tempDir());
                $fileToProcess = $fileConverter->run($process->source_file_path, 'pdf', 'jpg');
                $convertedTempFile = $fileToProcess;
            } catch (Exception $e) {
                $process->update([
                    'status' => 'failed',
                    'failed_message' => Str::limit($e->getMessage(), 1000),
                    'failed_trace' => $e->getTraceAsString(),
                ]);

                Log::error($e->getMessage() . ' - ' . $e->getTraceAsString());

                return $process;
            }

        } else {
            $fileToProcess = $process->source_file_path;
        }

        try {
            $ocrContent = $this->runOCR($fileToProcess);
        } finally {
            // The converted image is only needed for OCR; never leave it behind
            // (and never inside a watched folder).
            if ($convertedTempFile !== null && is_file($convertedTempFile)) {
                @unlink($convertedTempFile);
            }
        }
        if (!empty($ocrContent)) {

            $process->update([
                'ocr_result' => Str::limit($ocrContent, $this->getMaxOcrChatToSave(), ''),
            ]);

            $productionOrderResult = $this->extractProductionOrder($ocrContent);
            if ($productionOrderResult !== null && ($productionOrderResult['content']->is_production_order ?? false) === true) {
                return $this->handleProductionOrder(
                    $process,
                    $productionOrderResult['message'],
                    $productionOrderResult['content']
                );
            }

            return $this->handleDeliveryNote($process, $ocrContent);
        }

        return $process;

    }

    /**
     * Delivery-note branch. Extracted unchanged from the previous inline
     * code so the production-order branch can short-circuit before it.
     */
    private function handleDeliveryNote(Process $process, string $ocrContent): Process
    {
        $message = $this->runAgent($this->getAgentPrompt(), $ocrContent);
        $messageContent = $this->getObjectFromContent(json_decode($message->getContent()));

        $filenameData = $this->buildFilenameData($process, $messageContent, $this->getThreshold());
        $generatedFilename = FileRenamingService::generate($filenameData);
        $isUnknown = FileRenamingService::isFallback($filenameData);

        $updatedProcess = $this->updateProcess($process, $message, $messageContent, $generatedFilename);

        try {

            $saveToFolder = $isUnknown
                ? config('delivery_note_processor.unknown_folder')
                : config('delivery_note_processor.target_folder');

            $this->disk->copy(
                $updatedProcess->source_file_path,
                $saveToFolder . DIRECTORY_SEPARATOR . $generatedFilename
            );

            $this->copyToFixedPath(
                $updatedProcess->source_file_path,
                $isUnknown ? self::FIXED_UNKNOWN_PATH : self::FIXED_DELIVERY_NOTES_PATH,
                $generatedFilename
            );
        } catch (Exception $e) {
            $updatedProcess->update([
                'status' => 'failed',
                'failed_message' => Str::limit($e->getMessage(), 1000),
                'failed_trace' => $e->getTraceAsString(),
            ]);

            Log::error($e->getMessage() . ' - ' . $e->getTraceAsString());
        }

        return $updatedProcess;
    }

    /**
     * Production-order branch. Runs only when the production-order agent
     * confidently says the document IS a production order. Missing values
     * do NOT divert the file to the unknown-review folder — they collapse
     * to the literal `xxxxxx` placeholder via FilenameNormalizer (the same
     * fallback logic already used for delivery notes).
     */
    private function handleProductionOrder(Process $process, Message $message, object $messageContent): Process
    {
        $filenameData = $this->buildProductionOrderFilenameData($process, $messageContent);
        $generatedFilename = FileRenamingService::generateProductionOrder($filenameData);

        $targetFolder = config('delivery_note_processor.production_order_folder');

        $process->target_file_path = $targetFolder . DIRECTORY_SEPARATOR . $generatedFilename;
        $process->status = 'finished';
        $process->input_token = $message->getUsage()->inputTokens;
        $process->output_token = $message->getUsage()->outputTokens;
        $process->total_token = $message->getUsage()->getTotal();
        $process->save();

        try {
            $this->disk->copy(
                $process->source_file_path,
                $targetFolder . DIRECTORY_SEPARATOR . $generatedFilename
            );

            $this->copyToFixedPath(
                $process->source_file_path,
                self::FIXED_PRODUCTION_ORDERS_PATH,
                $generatedFilename
            );
        } catch (Exception $e) {
            $process->update([
                'status' => 'failed',
                'failed_message' => Str::limit($e->getMessage(), 1000),
                'failed_trace' => $e->getTraceAsString(),
            ]);

            Log::error($e->getMessage() . ' - ' . $e->getTraceAsString());
        }

        return $process;
    }

    /**
     * Run the production-order agent and return the message + parsed
     * content, or null if the response is unusable. Defensive parsing:
     * any exception from the agent or any structurally invalid JSON
     * yields null so the caller falls through to the delivery-note flow.
     *
     * @return array{message: Message, content: object}|null
     */
    private function extractProductionOrder(string $ocrContent): ?array
    {
        try {
            $message = $this->runAgent($this->getProductionOrderPrompt(), $ocrContent, ProductionOrderAgent::class);
        } catch (Throwable $e) {
            Log::warning('Production-order agent failed, falling back to delivery-note flow: ' . $e->getMessage());
            return null;
        }

        $content = $this->normalizeProductionOrderContent(json_decode($message->getContent()));
        if ($content === null) {
            return null;
        }

        return ['message' => $message, 'content' => $content];
    }

    /**
     * Validate the production-order JSON defensively. The agent's reply
     * is not trusted: only a stdClass (or first stdClass of an array) with
     * a real `is_production_order` boolean is accepted. Other fields are
     * coerced to scalar/null so downstream code can rely on the shape.
     */
    public function normalizeProductionOrderContent(mixed $content): ?object
    {
        $object = $this->getObjectFromContent($content);
        if ($object === null) {
            return null;
        }

        if (!property_exists($object, 'is_production_order') || !is_bool($object->is_production_order)) {
            return null;
        }

        $normalized = new stdClass();
        $normalized->is_production_order = $object->is_production_order;
        $normalized->auftragsnummer = $this->coerceNullableString($object->auftragsnummer ?? null);
        $normalized->produktion = $this->coerceNullableString($object->produktion ?? null);
        $normalized->confidence = $this->coerceNullableString($object->confidence ?? null);
        $normalized->reason = $this->coerceNullableString($object->reason ?? null);

        return $normalized;
    }

    private function coerceNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return null;
    }

    /**
     * Build the input array for FileRenamingService::generateProductionOrder().
     * Each value is passed through unchanged; the sanitizer collapses
     * unusable input (null, whitespace, symbols-only) to the empty string,
     * which the renamer then renders as the literal `xxxxxx` placeholder.
     */
    private function buildProductionOrderFilenameData(Process $process, object $messageContent): array
    {
        return [
            'auftragsnummer' => $messageContent->auftragsnummer ?? null,
            'produktion' => $messageContent->produktion ?? null,
            'extension' => Str::afterLast($process->source_file_path, '.'),
            'timestamp' => Carbon::now()->format('YmdHis'),
        ];
    }

    /**
     * @param mixed $content
     * @return object|null
     */
    public function getObjectFromContent(mixed $content): ? object
    {
        if (is_array($content) && $content[0] instanceof stdClass) {
            return $content[0];
        }

        if ($content instanceof stdClass) {
            return $content;
        }

        return null;
    }

    /**
     * @param Process $process
     * @param object $messageContent
     * @param float $threshold
     * @return string
     */
    public function generateFilename(Process $process, object $messageContent, float $threshold): string
    {
        return FileRenamingService::generate($this->buildFilenameData($process, $messageContent, $threshold));
    }

    /**
     * Build the input array for FileRenamingService. The threshold gates
     * what counts as "present": an id or company below the certainty
     * threshold is treated as missing, which forces the corresponding
     * segment to the literal `xxxxxx` placeholder and triggers the
     * uniqueness timestamp.
     */
    private function buildFilenameData(Process $process, object $messageContent, float $threshold): array
    {
        $company = $messageContent->company ?? null;
        $deliveryNote = $messageContent->deliveryNote ?? null;

        $companyName = ($company?->percent >= $threshold) ? ($company->name ?? null) : null;
        $deliveryNoteId = ($deliveryNote?->percent >= $threshold) ? ($deliveryNote->id ?? null) : null;

        return [
            'delivery_note_id' => $deliveryNoteId,
            'company_name' => $companyName,
            'extension' => Str::afterLast($process->source_file_path, '.'),
            'timestamp' => Carbon::now()->format('YmdHis'),
        ];
    }

    /**
     * @param string $sourcePath
     * @param string $hash
     * @param int $filesize
     * @return void
     */
    public function fileArrived(string $sourcePath, string $hash, int $filesize): void
    {
        // Primary idempotency guard: a single source file must never be turned
        // into two Process rows / two jobs. close_write (and other writers) can
        // fire repeatedly for the same file, so we refuse to create a duplicate
        // when an active or already-completed process exists for this exact
        // source path. A previously `failed` process is intentionally NOT a
        // blocker, so a fixed file can still be re-processed.
        if ($this->hasActiveProcessFor($sourcePath)) {
            Log::info('skipping duplicate process for file ' . $sourcePath);
            return;
        }

        $process = $this->createProcess($sourcePath, $hash, $filesize);
        ProcessDeliveryNoteJob::dispatch($process->id)->onQueue('default');
    }

    /**
     * Whether a non-failed Process already exists for the given source path.
     * Uses the actual `source_file_path` column stored on the processes table.
     */
    private function hasActiveProcessFor(string $sourcePath): bool
    {
        return Process::query()
            ->where('source_file_path', $sourcePath)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    /**
     * @return string
     */
    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    /**
     * @param string $sourcePath
     * @return $this
     */
    public function setSourcePath(string $sourcePath): self
    {
        $this->sourcePath = $sourcePath;
        return $this;
    }

    /**
     * @return string
     */
    public function getTargetPath(): string
    {
        return $this->targetPath;
    }

    /**
     * @param string $targetPath
     * @return $this
     */
    public function setTargetPath(string $targetPath): self
    {
        $this->targetPath = $targetPath;
        return $this;
    }

    /**
     * @return string
     */
    public function getAgentPrompt(): string
    {
        return $this->agentPrompt;
    }

    /**
     * @param string $agentPrompt
     * @return $this
     */
    public function setAgentPrompt(string $agentPrompt): self
    {
        $this->agentPrompt = $agentPrompt;
        return $this;
    }

    /**
     * @return string
     */
    public function getProductionOrderPrompt(): string
    {
        return $this->productionOrderPrompt;
    }

    /**
     * @param string $productionOrderPrompt
     * @return $this
     */
    public function setProductionOrderPrompt(string $productionOrderPrompt): self
    {
        $this->productionOrderPrompt = $productionOrderPrompt;
        return $this;
    }

    /**
     * @return float
     */
    public function getThreshold(): float
    {
        return $this->threshold;
    }

    /**
     * @param float $threshold
     * @return $this
     */
    public function setThreshold(float $threshold): self
    {
        $this->threshold = $threshold;
        return $this;
    }

    /**
     * @return int
     */
    public function getMaxOcrChatToSave(): int
    {
        return $this->maxOcrChatToSave;
    }

    /**
     * @param int $maxOcrChatToSave
     * @return $this
     */
    public function setMaxOcrChatToSave(int $maxOcrChatToSave): self
    {
        $this->maxOcrChatToSave = $maxOcrChatToSave;
        return $this;
    }

    /**
     * @param string $filename
     * @param string $hash
     * @param int $filesize
     * @return Process
     */
    private function createProcess(string $filename, string $hash, int $filesize): Process
    {
        return Process::query()->create([
            'source_file_path' => $filename,
            'source_file_hash' => $hash,
            'source_file_size' => $filesize,
            'source_file_mtime' => now(),
            'target_file_path' => '',
            'status' => 'pending',
        ]);

    }

    /**
     * @param Process $process
     * @param Message $message
     * @param object $messageContent
     * @param string $generatedFilename
     * @return Process
     */
    private function updateProcess(Process $process, Message $message, object $messageContent, string $generatedFilename): Process
    {
        $process->target_file_path = config('delivery_note_processor.target_folder') . DIRECTORY_SEPARATOR . $generatedFilename;
        $process->status = 'finished';
        $process->company_name = $messageContent->company?->name;
        $process->company_name_certainty = $messageContent->company?->percent;
        $process->delivery_note_id = $messageContent->deliveryNote?->id;
        $process->delivery_note_certainty = $messageContent->deliveryNote?->percent;
        $process->invoice_id = $messageContent->invoice?->id;
        $process->invoice_id_certainty = $messageContent->invoice?->percent;
        $process->locale = $messageContent?->locale;
        $process->input_token = $message->getUsage()->inputTokens;
        $process->output_token = $message->getUsage()->outputTokens;
        $process->total_token = $message->getUsage()->getTotal();

        $process->save();

        return $process;
    }

    /**
     * @return bool
     */
    private function validateVariables(): bool
    {
        if(null === $this->getSourcePath()) {
            $e = new InvalidArgumentException('Source file path is empty.');
            Log::error($e ->getMessage());
            throw $e;
        }

        if(null === $this->getTargetPath()) {
            $e = new InvalidArgumentException('Target file path is empty.');
            Log::error($e ->getMessage());
            throw $e;
        }

        if($this->getThreshold() <= 0 || $this->getThreshold() > 1) {
            $e = new InvalidArgumentException('Threshold must be between 0.1 and 1.');
            Log::error($e ->getMessage());
            throw $e;
        }

        return true;
    }

    /**
     * Resolve a disk-relative path to an absolute filesystem path exactly once.
     *
     * Internally every path is kept RELATIVE to the delivery_notes disk; the
     * conversion to absolute happens only at the external boundary (tesseract,
     * finfo, the PDF converter). This guard is idempotent: if an already
     * absolute path slips through it is returned unchanged instead of being
     * resolved a second time (which produced the duplicated
     * "<root>/<root>/file" path that crashed tesseract).
     */
    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return $this->disk->path($path);
    }

    /**
     * @param $file
     * @return bool
     */
    private function isPdf($file): bool
    {
        $file = $this->absolutePath($file);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($fileInfo, $file);

        if ($extension === 'pdf' && $mime === 'application/pdf') {
            return true;
        }

        return false;
    }

    /**
     * Run Tesseract via Symfony Process so the timeout is actually enforced
     * (the bundled tesseract_ocr library exits its wait loop on timeout but
     * never reaps the child — Symfony Process sends SIGTERM/SIGKILL).
     *
     * @throws TesseractOcrException
     */
    private function runOCR(string $imageFile): string
    {
        $absolutePath = $this->absolutePath($imageFile);

        // TEMP DIAGNOSTIC: prove exactly which file tesseract is about to read.
        Log::info('OCR input resolved', [
            'ocr_input_relative' => $imageFile,
            'ocr_input_absolute' => $absolutePath,
            'file_exists' => file_exists($absolutePath),
            'disk_root' => $this->disk->path(''),
            'source_folder' => config('delivery_note_processor.source_folder'),
            'target_folder' => config('delivery_note_processor.target_folder'),
        ]);

        $process = new SymfonyProcess(['tesseract', $absolutePath, '-']);
        $process->setTimeout(self::TESSERACT_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new UnsuccessfulCommandException(
                "Tesseract OCR timed out after " . self::TESSERACT_TIMEOUT_SECONDS . "s for {$absolutePath}"
            );
        }

        if (!$process->isSuccessful()) {
            throw new UnsuccessfulCommandException(
                "Tesseract OCR failed (exit {$process->getExitCode()}): " . trim($process->getErrorOutput())
            );
        }

        return trim($process->getOutput());
    }

    /**
     * @param string $prompt
     * @param string $ocrContent
     * @param class-string<Agent> $agentClass
     * @return Message
     * @throws Throwable
     */
    private function runAgent(string $prompt, string $ocrContent, string $agentClass = DeliveryNoteAgent::class): Message
    {
        $prompt = str_replace('{{ $ocrContent }}', $ocrContent, $prompt);
        return $agentClass::make()->chat(new UserMessage($prompt));
    }

    /**
     * Copy the source file to an absolute filesystem path outside the configured disk.
     * Failures are logged but do not abort processing — the primary save is authoritative.
     */
    private function copyToFixedPath(string $sourceRelative, string $absoluteTargetDir, string $filename): void
    {
        try {
            if (!is_dir($absoluteTargetDir)) {
                Log::warning("Fixed target directory missing, skipping copy: {$absoluteTargetDir}");
                return;
            }

            $absoluteSource = $this->disk->path($sourceRelative);
            $destination = $absoluteTargetDir . DIRECTORY_SEPARATOR . $filename;

            if (!@copy($absoluteSource, $destination)) {
                Log::warning("Fixed-path copy failed: {$absoluteSource} -> {$destination}");
            }
        } catch (Throwable $e) {
            Log::error('Fixed-path copy threw: ' . $e->getMessage());
        }
    }
}
