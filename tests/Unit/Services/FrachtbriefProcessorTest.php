<?php

declare(strict_types=1);

use App\Models\Process;
use App\Neuron\DeliveryNoteAgent;
use App\Neuron\FrachtbriefAgent;
use App\Neuron\ProductionOrderAgent;
use App\Services\DeliveryNoteProcessorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * Test double that replaces the two external boundaries of the pipeline —
 * Tesseract and the OpenAI agents — with canned values, so the branch routing
 * can be exercised end-to-end without a tesseract binary or a network call.
 *
 * Nothing else is overridden: the real prompts, the real normalization, the
 * real CompanyResolverService, the real FileRenamingService and the real disk
 * writes (against Storage::fake) all run.
 */
final class FakeAgentProcessor extends DeliveryNoteProcessorService
{
    /** @var list<class-string> agent classes actually invoked, in order */
    public array $agentCalls = [];

    /** @var array<class-string, Message|Throwable> */
    private array $responses = [];

    private string $ocr = '';

    public function withOcr(string $ocr): self
    {
        $this->ocr = $ocr;
        return $this;
    }

    public function withResponse(string $agentClass, Message|Throwable $response): self
    {
        $this->responses[$agentClass] = $response;
        return $this;
    }

    protected function runOCR(string $imageFile): string
    {
        return $this->ocr;
    }

    protected function runAgent(string $prompt, string $ocrContent, string $agentClass = DeliveryNoteAgent::class): Message
    {
        $this->agentCalls[] = $agentClass;

        $response = $this->responses[$agentClass] ?? null;

        if ($response instanceof Throwable) {
            throw $response;
        }

        if ($response === null) {
            throw new RuntimeException("Test double has no canned response for {$agentClass}");
        }

        return $response;
    }
}

function agentMessage(string $json): Message
{
    return (new AssistantMessage($json))->setUsage(new Usage(100, 20));
}

function frachtbriefJson(array $overrides = []): string
{
    return json_encode(array_replace([
        'is_frachtbrief' => true,
        'document_confidence' => 0.96,
        'order_number' => ['value' => '260630-01', 'confidence' => 0.98],
        'pickup_date' => ['value' => '2026-06-30', 'confidence' => 0.92],
        'recipient_company' => ['value' => 'Musterfirma GmbH', 'confidence' => 0.91],
    ], $overrides), JSON_THROW_ON_ERROR);
}

function notAFrachtbriefJson(): string
{
    return json_encode([
        'is_frachtbrief' => false,
        'document_confidence' => 0.1,
        'order_number' => ['value' => null, 'confidence' => null],
        'pickup_date' => ['value' => null, 'confidence' => null],
        'recipient_company' => ['value' => null, 'confidence' => null],
    ], JSON_THROW_ON_ERROR);
}

function productionOrderJson(bool $isProductionOrder = true): string
{
    return json_encode([
        'is_production_order' => $isProductionOrder,
        'auftragsnummer' => $isProductionOrder ? '123456' : null,
        'produktion' => $isProductionOrder ? '98765' : null,
        'missing_values' => [],
        'confidence' => $isProductionOrder ? 'high' : 'low',
        'reason' => 'test',
    ], JSON_THROW_ON_ERROR);
}

function deliveryNoteJson(): string
{
    return json_encode([
        'locale' => 'de',
        'company' => ['name' => 'ACME Logistics', 'percent' => '0.95'],
        'deliveryNote' => ['id' => 'DN-99', 'percent' => '0.95'],
        'invoice' => ['id' => 'INV-1', 'percent' => '0.2'],
    ], JSON_THROW_ON_ERROR);
}

const FRACHTBRIEF_OCR = <<<'TXT'
    Spedition Nord GmbH
                                            Order Nummer: 260630-01
    Absender: Werk Sued
    Empfaenger: Musterfirma GmbH
    Abholdatum: 30.06.2026
    Ladestelle: Tor 4
    TXT;

const DELIVERY_NOTE_OCR = <<<'TXT'
    ACME Logistics GmbH
    Lieferschein Nr. DN-99
    Ihre Bestellnummer: 44120
    TXT;

function storedProcess(string $filename = 'scan.pdf'): Process
{
    $path = config('delivery_note_processor.source_folder') . '/' . $filename;

    Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->put($path, 'file-content');

    return Process::query()->create([
        'source_file_path' => $path,
        'source_file_hash' => 'hash',
        'source_file_size' => 12,
        'source_file_mtime' => now(),
        'target_file_path' => '',
        'status' => 'running',
    ]);
}

beforeEach(function () {
    Storage::fake('delivery_notes');
    Carbon::setTestNow(Carbon::create(2026, 7, 24, 14, 30, 25));
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| 1-5. Detection and fallthrough
|--------------------------------------------------------------------------
*/

it('routes a confirmed frachtbrief and never reaches the production-order or delivery-note agents', function () {
    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson()));

    $result = $processor->run(storedProcess());

    expect($processor->agentCalls)->toBe([FrachtbriefAgent::class])
        ->and($result->document_type)->toBe(DeliveryNoteProcessorService::DOCUMENT_TYPE_FRACHTBRIEF)
        ->and($result->status)->toBe('finished')
        ->and(basename($result->target_file_path))->toBe('FB_musterfirma-gmbh_2026-06-30_260630-01.pdf');
});

it('falls through to the production-order flow when the agent says it is not a frachtbrief', function () {
    $processor = (new FakeAgentProcessor())
        ->withOcr(DELIVERY_NOTE_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(notAFrachtbriefJson()))
        ->withResponse(ProductionOrderAgent::class, agentMessage(productionOrderJson(true)));

    $result = $processor->run(storedProcess());

    expect($processor->agentCalls)->toBe([FrachtbriefAgent::class, ProductionOrderAgent::class])
        ->and($result->document_type)->toBeNull()
        ->and($result->target_file_path)
        ->toBe(config('delivery_note_processor.production_order_folder') . '/PA_123456_98765.pdf');
});

it('falls through to the existing flow and logs when the frachtbrief agent throws', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn(string $message): bool => str_contains($message, 'Frachtbrief agent failed'));
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    $processor = (new FakeAgentProcessor())
        ->withOcr(DELIVERY_NOTE_OCR)
        ->withResponse(FrachtbriefAgent::class, new RuntimeException('openai exploded'))
        ->withResponse(ProductionOrderAgent::class, agentMessage(productionOrderJson(true)));

    $result = $processor->run(storedProcess());

    expect($processor->agentCalls)->toBe([FrachtbriefAgent::class, ProductionOrderAgent::class])
        ->and($result->status)->toBe('finished')
        ->and($result->document_type)->toBeNull();
});

it('falls through to the existing flow when the frachtbrief response is malformed', function (string $malformed) {
    $processor = (new FakeAgentProcessor())
        ->withOcr(DELIVERY_NOTE_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage($malformed))
        ->withResponse(ProductionOrderAgent::class, agentMessage(productionOrderJson(true)));

    $result = $processor->run(storedProcess());

    expect($processor->agentCalls)->toBe([FrachtbriefAgent::class, ProductionOrderAgent::class])
        ->and($result->document_type)->toBeNull()
        ->and($result->status)->toBe('finished');
})->with([
    'not json at all',
    '{"broken": ',
    '{}',                                        // no is_frachtbrief key
    '{"is_frachtbrief": "true"}',                // string, not a real boolean
    '{"is_frachtbrief": 1}',                     // int, not a real boolean
    '[]',
    'null',
]);

it('falls through to the existing flow when the document confidence is below the threshold', function () {
    // Delivery-note OCR on purpose: the delivery-note branch must be able to
    // resolve "ACME Logistics", and CompanyResolverService requires the name to
    // actually appear in the OCR text before it will trust it.
    $processor = (new FakeAgentProcessor())
        ->withOcr(DELIVERY_NOTE_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson(['document_confidence' => 0.5])))
        ->withResponse(ProductionOrderAgent::class, agentMessage(productionOrderJson(false)))
        ->withResponse(DeliveryNoteAgent::class, agentMessage(deliveryNoteJson()));

    $result = $processor->run(storedProcess());

    expect($processor->agentCalls)
        ->toBe([FrachtbriefAgent::class, ProductionOrderAgent::class, DeliveryNoteAgent::class])
        ->and($result->document_type)->toBeNull()
        ->and(basename($result->target_file_path))->toBe('ls_dn99_acme-logistics.pdf');
});

it('falls through when the confidence is missing entirely', function () {
    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson(['document_confidence' => null])))
        ->withResponse(ProductionOrderAgent::class, agentMessage(productionOrderJson(true)));

    $result = $processor->run(storedProcess());

    expect($result->document_type)->toBeNull();
});

it('falls through when there is no order-number evidence anywhere', function () {
    // Agent claims a Frachtbrief with high confidence but extracted no order
    // number, and the OCR text has no `Order Nummer` pattern either.
    $processor = (new FakeAgentProcessor())
        ->withOcr(DELIVERY_NOTE_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'order_number' => ['value' => null, 'confidence' => null],
        ])))
        ->withResponse(ProductionOrderAgent::class, agentMessage(productionOrderJson(true)));

    $result = $processor->run(storedProcess());

    expect($processor->agentCalls)->toBe([FrachtbriefAgent::class, ProductionOrderAgent::class])
        ->and($result->document_type)->toBeNull();
});

it('does not treat a plain Bestellnummer as order-number evidence', function () {
    $service = new DeliveryNoteProcessorService();

    $content = $service->normalizeFrachtbriefContent(json_decode(frachtbriefJson([
        'order_number' => ['value' => null, 'confidence' => null],
    ])));

    expect($service->isConfirmedFrachtbrief($content, "Bestellnummer: 260630-01\nAuftragsnummer: 4711"))->toBeFalse();
});

it('accepts order-number evidence found only in the raw ocr text', function (string $label) {
    $service = new DeliveryNoteProcessorService();

    $content = $service->normalizeFrachtbriefContent(json_decode(frachtbriefJson([
        'order_number' => ['value' => null, 'confidence' => null],
    ])));

    expect($service->isConfirmedFrachtbrief($content, "Kopfzeile\n{$label} 260630-01\nAbholdatum"))->toBeTrue();
})->with([
    'Order Nummer:',
    'Ordernummer:',
    'Order Nr.',
    'Order-Nr.:',
    '0rder Nummer:',
    '0rder Numner:',
]);

/*
|--------------------------------------------------------------------------
| 6-10. Extraction
|--------------------------------------------------------------------------
*/

it('extracts company, pickup date and order number from a complete frachtbrief', function () {
    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson()));

    $result = $processor->run(storedProcess());

    expect($result->frachtbrief_recipient_company)->toBe('Musterfirma GmbH')
        ->and((float)$result->frachtbrief_recipient_company_percent)->toBe(0.91)
        ->and($result->frachtbrief_pickup_date)->toBe('2026-06-30')
        ->and((float)$result->frachtbrief_pickup_date_percent)->toBe(0.92)
        ->and($result->frachtbrief_order_number)->toBe('260630-01')
        ->and((float)$result->frachtbrief_order_number_percent)->toBe(0.98);
});

it('derives the pickup date from the order number when the abholdatum is missing', function () {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn(string $message): bool => str_contains($message, 'derived from the order number'));
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'pickup_date' => ['value' => null, 'confidence' => null],
        ])));

    $result = $processor->run(storedProcess());

    expect($result->frachtbrief_pickup_date)->toBe('2026-06-30')
        ->and(basename($result->target_file_path))->toBe('FB_musterfirma-gmbh_2026-06-30_260630-01.pdf');
});

it('prefers the explicit abholdatum over the order-derived date and logs the mismatch', function () {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn(string $message): bool => str_contains($message, 'pickup-date mismatch'));
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            // order number implies 2026-06-30, the document says otherwise
            'pickup_date' => ['value' => '2026-07-02', 'confidence' => 0.9],
        ])));

    $result = $processor->run(storedProcess());

    expect($result->frachtbrief_pickup_date)->toBe('2026-07-02')
        ->and(basename($result->target_file_path))->toBe('FB_musterfirma-gmbh_2026-07-02_260630-01.pdf');
});

it('uses the xxxxxx placeholder when the recipient company cannot be resolved', function () {
    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'recipient_company' => ['value' => null, 'confidence' => null],
        ])));

    $result = $processor->run(storedProcess());

    expect($result->frachtbrief_recipient_company)->toBeNull()
        ->and(basename($result->target_file_path))
        ->toBe('FB_xxxxxx_2026-06-30_260630-01_20260724143025.pdf');
});

it('uses the xxxxxx placeholder when no valid pickup date can be determined', function () {
    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            // 999999-01 has no valid YYMMDD prefix, so nothing can be derived
            'order_number' => ['value' => '999999-01', 'confidence' => 0.9],
            'pickup_date' => ['value' => null, 'confidence' => null],
        ])));

    $result = $processor->run(storedProcess());

    expect($result->frachtbrief_pickup_date)->toBeNull()
        ->and(basename($result->target_file_path))
        ->toBe('FB_musterfirma-gmbh_xxxxxx_999999-01_20260724143025.pdf');
});

/*
|--------------------------------------------------------------------------
| 16-17. Destination
|--------------------------------------------------------------------------
*/

it('writes a complete frachtbrief into the configured frachtbrief folder', function () {
    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson()));

    $result = $processor->run(storedProcess());

    $expected = config('delivery_note_processor.frachtbrief_folder')
        . '/FB_musterfirma-gmbh_2026-06-30_260630-01.pdf';

    expect($result->target_file_path)->toBe($expected)
        ->and(Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->exists($expected))->toBeTrue();
});

it('routes a confirmed frachtbrief without an order number to the existing unknown folder', function () {
    // Evidence comes from the OCR text, so the document IS confirmed, but the
    // agent could not read the value itself.
    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'order_number' => ['value' => null, 'confidence' => null],
        ])));

    $result = $processor->run(storedProcess());

    $expected = config('delivery_note_processor.unknown_folder')
        . '/FB_musterfirma-gmbh_2026-06-30_xxxxxx_20260724143025.pdf';

    expect($result->document_type)->toBe(DeliveryNoteProcessorService::DOCUMENT_TYPE_FRACHTBRIEF)
        ->and($result->target_file_path)->toBe($expected)
        ->and(Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->exists($expected))->toBeTrue();
});

it('resolves the frachtbrief destination without touching ocr, the agent or the disk', function () {
    $service = new DeliveryNoteProcessorService();

    $process = new Process();
    $process->forceFill(['source_file_path' => 'source/scan.jpg']);

    $destination = $service->resolveFrachtbriefDestination($process, 'musterfirma', '2026-06-30', '260630-01');

    expect($destination['is_unknown'])->toBeFalse()
        ->and($destination['folder'])->toBe(config('delivery_note_processor.frachtbrief_folder'))
        ->and($destination['filename'])->toBe('FB_musterfirma_2026-06-30_260630-01.jpg');
});

it('flags the destination as unknown when the order number is unusable', function () {
    $service = new DeliveryNoteProcessorService();

    $process = new Process();
    $process->forceFill(['source_file_path' => 'source/scan.pdf']);

    $destination = $service->resolveFrachtbriefDestination($process, 'musterfirma', '2026-06-30', 'not-an-order');

    expect($destination['is_unknown'])->toBeTrue()
        ->and($destination['folder'])->toBe(config('delivery_note_processor.unknown_folder'));
});

/*
|--------------------------------------------------------------------------
| Response normalization
|--------------------------------------------------------------------------
*/

it('normalizes a well-formed frachtbrief payload', function () {
    $service = new DeliveryNoteProcessorService();

    $normalized = $service->normalizeFrachtbriefContent(json_decode(frachtbriefJson()));

    expect($normalized)->not->toBeNull()
        ->and($normalized->is_frachtbrief)->toBeTrue()
        ->and($normalized->document_confidence)->toBe(0.96)
        ->and($normalized->order_number)->toBe('260630-01')
        ->and($normalized->order_number_percent)->toBe(0.98)
        ->and($normalized->pickup_date)->toBe('2026-06-30')
        ->and($normalized->recipient_company)->toBe('Musterfirma GmbH');
});

it('rejects frachtbrief payloads without a real boolean is_frachtbrief', function (mixed $payload) {
    $service = new DeliveryNoteProcessorService();

    expect($service->normalizeFrachtbriefContent($payload))->toBeNull();
})->with([
    (object)['is_frachtbrief' => 'true'],
    (object)['is_frachtbrief' => 1],
    (object)['order_number' => (object)['value' => '260630-01']],
    null,
    'not-an-object',
]);

it('treats out-of-range and non-numeric confidences as unusable', function () {
    $service = new DeliveryNoteProcessorService();

    $normalized = $service->normalizeFrachtbriefContent((object)[
        'is_frachtbrief' => true,
        'document_confidence' => 96,          // 0..1 field answered as a percentage
        'order_number' => (object)['value' => '260630-01', 'confidence' => 'high'],
    ]);

    expect($normalized->document_confidence)->toBeNull()
        ->and($normalized->order_number_percent)->toBeNull()
        ->and($normalized->order_number)->toBe('260630-01');
});

it('tolerates a bare scalar where a value/confidence object was expected', function () {
    $service = new DeliveryNoteProcessorService();

    $normalized = $service->normalizeFrachtbriefContent((object)[
        'is_frachtbrief' => true,
        'document_confidence' => 0.9,
        'order_number' => '260630-01',
        'recipient_company' => 'Musterfirma GmbH',
    ]);

    expect($normalized->order_number)->toBe('260630-01')
        ->and($normalized->order_number_percent)->toBeNull()
        ->and($normalized->recipient_company)->toBe('Musterfirma GmbH')
        ->and($normalized->pickup_date)->toBeNull();
});

it('accepts a frachtbrief payload wrapped in a single-element array', function () {
    $service = new DeliveryNoteProcessorService();

    $normalized = $service->normalizeFrachtbriefContent([(object)['is_frachtbrief' => false]]);

    expect($normalized)->not->toBeNull()
        ->and($normalized->is_frachtbrief)->toBeFalse();
});
