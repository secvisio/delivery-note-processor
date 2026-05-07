<?php

declare(strict_types=1);

use App\Models\Process;
use App\Services\DeliveryNoteProcessorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class);

beforeEach(function () {
    Storage::fake('delivery_notes');
});

function makeProcess(array $attrs = []): Process
{
    $process = new Process();
    $process->forceFill(array_merge([
        'source_file_path' => 'source/scan.pdf',
        'source_file_hash' => 'h',
    ], $attrs));

    return $process;
}

it('returns the first object when content is an array of objects', function () {
    $service = new DeliveryNoteProcessorService();
    $payload = [(object) ['value' => 'first'], (object) ['value' => 'second']];

    expect($service->getObjectFromContent($payload))->toBe($payload[0]);
});

it('returns the object when content is a single object', function () {
    $service = new DeliveryNoteProcessorService();
    $payload = (object) ['value' => 'only'];

    expect($service->getObjectFromContent($payload))->toBe($payload);
});

it('returns null when content is not an object', function () {
    $service = new DeliveryNoteProcessorService();

    expect($service->getObjectFromContent(['not-an-object']))->toBeNull();
});

it('generates ls_{id}_{company}.ext when both clear the threshold', function () {
    $service = new DeliveryNoteProcessorService();
    $process = makeProcess();

    $messageContent = (object) [
        'company' => (object) ['name' => 'ACME Logistics', 'percent' => 0.9],
        'deliveryNote' => (object) ['id' => 'DN_123', 'percent' => 0.9],
        'invoice' => (object) ['id' => 'INV_1', 'percent' => 0.2],
    ];

    expect($service->generateFilename($process, $messageContent, 0.6))
        ->toBe('ls_dn123_acme-logistics.pdf');
});

it('falls back to xxxxxx_{company}_{timestamp} when delivery id is below threshold', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 5, 12, 40, 55));

    $service = new DeliveryNoteProcessorService();
    $process = makeProcess();

    $messageContent = (object) [
        'company' => (object) ['name' => 'ACME Logistics', 'percent' => 0.9],
        'deliveryNote' => (object) ['id' => 'DN_123', 'percent' => 0.1],
        'invoice' => (object) ['id' => 'INV_42', 'percent' => 0.9],
    ];

    expect($service->generateFilename($process, $messageContent, 0.6))
        ->toBe('ls_xxxxxx_acme-logistics_20260505124055.pdf');

    Carbon::setTestNow();
});

it('falls back to ls_xxxxxx_xxxxxx_{timestamp} when company is below threshold and id missing', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 5, 12, 40, 55));

    $service = new DeliveryNoteProcessorService();
    $process = makeProcess();

    $messageContent = (object) [
        'company' => (object) ['name' => 'ACME Logistics', 'percent' => 0.4],
        'deliveryNote' => (object)['id' => null, 'percent' => 0.0],
        'invoice' => (object) ['id' => 'INV_42', 'percent' => 0.9],
    ];

    expect($service->generateFilename($process, $messageContent, 0.6))
        ->toBe('ls_xxxxxx_xxxxxx_20260505124055.pdf');

    Carbon::setTestNow();
});
