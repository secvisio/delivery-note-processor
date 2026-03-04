<?php

declare(strict_types=1);

use App\Services\DeliveryNoteProcessorService;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class);

beforeEach(function () {
    Storage::fake('delivery_notes');
});

it('returns the first object when content is an array of objects', function () {
    $service = new DeliveryNoteProcessorService();
    $payload = [(object) ['value' => 'first'], (object) ['value' => 'second']];

    $result = $service->getObjectFromContent($payload);

    expect($result)->toBe($payload[0]);
});

it('returns the object when content is a single object', function () {
    $service = new DeliveryNoteProcessorService();
    $payload = (object) ['value' => 'only'];

    $result = $service->getObjectFromContent($payload);

    expect($result)->toBe($payload);
});

it('returns null when content is not an object', function () {
    $service = new DeliveryNoteProcessorService();

    $result = $service->getObjectFromContent(['not-an-object']);

    expect($result)->toBeNull();
});

it('generates a delivery note filename when company and delivery note meet threshold', function () {
    $service = new DeliveryNoteProcessorService();

    $messageContent = (object) [
        'company' => (object) ['name' => 'ACME Logistics', 'percent' => 0.9],
        'deliveryNote' => (object) ['id' => 'DN_123', 'percent' => 0.9],
        'invoice' => (object) ['id' => 'INV_1', 'percent' => 0.2],
    ];

    $result = $service->generateFilename($messageContent, 0.6);

    expect($result)->toBe('acme_logistics_ls_dn_123.jpg');
});

it('falls back to invoice id when delivery note does not meet threshold', function () {
    $service = new DeliveryNoteProcessorService();

    $messageContent = (object) [
        'company' => (object) ['name' => 'ACME Logistics', 'percent' => 0.9],
        'deliveryNote' => (object) ['id' => 'DN_123', 'percent' => 0.1],
        'invoice' => (object) ['id' => 'INV_42', 'percent' => 0.9],
    ];

    $result = $service->generateFilename($messageContent, 0.6);

    expect($result)->toBe('acme_logistics_re_inv_42.jpg');
});

it('returns an unknown filename when company certainty is below threshold', function () {
    $service = new DeliveryNoteProcessorService();
    Carbon::setTestNow(Carbon::create(2026, 1, 2, 3, 4, 5));

    $messageContent = (object) [
        'company' => (object) ['name' => 'ACME Logistics', 'percent' => 0.4],
        'deliveryNote' => (object) ['id' => 'DN_123', 'percent' => 0.9],
        'invoice' => (object) ['id' => 'INV_42', 'percent' => 0.9],
    ];

    $result = $service->generateFilename($messageContent, 0.6);

    expect($result)->toBe('unknown_company_unknown_id_20260102030405.jpg');

    Carbon::setTestNow();
});

it('throws when source file is missing on disk', function () {
    $service = (new DeliveryNoteProcessorService())
        ->setSourcePath('incoming/missing.pdf')
        ->setTargetPath('processed')
        ->setThreshold(0.6);

    expect(fn () => $service->run())
        ->toThrow(FileNotFoundException::class);
});

it('throws when threshold is invalid', function () {
    $service = (new DeliveryNoteProcessorService())
        ->setSourcePath('incoming/file.pdf')
        ->setTargetPath('processed')
        ->setThreshold(1.5);

    expect(fn () => $service->run())
        ->toThrow(InvalidArgumentException::class, 'Threshold must be between 0.1 and 1.');
});
