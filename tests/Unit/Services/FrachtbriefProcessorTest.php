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
| Rhenus WebOrder detection (`WebOrdernr. WOO...`)
|--------------------------------------------------------------------------
|
| The secondary identifying signal. Everything here goes through the SAME
| gate as the primary one: the agent must still have answered
| is_frachtbrief = true with a confidence at/above the threshold — a regex
| match alone never classifies a document.
*/

/** Payload with no extracted order number, so the OCR evidence path is exercised. */
function frachtbriefWithoutOrderNumber(): object
{
    return (new DeliveryNoteProcessorService())->normalizeFrachtbriefContent(json_decode(frachtbriefJson([
        'order_number' => ['value' => null, 'confidence' => null],
    ])));
}

it('accepts a labelled weborder number found in the raw ocr text', function (string $line) {
    $service = new DeliveryNoteProcessorService();

    // Deliberately WITHOUT the words "Rhenus" or "Frachtbrief": a correctly
    // labelled WebOrder value is sufficient evidence on its own.
    expect($service->isConfirmedFrachtbrief(frachtbriefWithoutOrderNumber(), "Kopfzeile\n{$line}\nAbholdatum"))
        ->toBeTrue();
})->with([
    'WebOrdernr. WOO0000006176714',
    'WebOrdernr WOO0000006176714',
    'WebOrder-Nr. WOO0000006176714',
    'Web Order Nr. WOO0000006176714',
    'WebOrdernr.: WOO0000006176714',
    'Web0rdernr. WOO0000006176714',      // OCR read the O as a zero
    'WebOrdernr. W000000006176714',      // OCR read the prefix O's as zeros
    'weborder nr woo0000006176714',      // fully lowercased
]);

it('accepts a labelled weborder number together with the optional corroborating terms', function (string $ocr) {
    $service = new DeliveryNoteProcessorService();

    expect($service->isConfirmedFrachtbrief(frachtbriefWithoutOrderNumber(), $ocr))->toBeTrue();
})->with([
    "Rhenus Logistics GmbH\nWebOrdernr. WOO0000006176714\n",
    "Frachtbrief\nWebOrdernr. WOO0000006176714\n",
    "Rhenus Logistics GmbH\nFrachtbrief\nWebOrdernr. WOO0000006176714\n",
]);

it('accepts a bare weborder number only when rhenus or frachtbrief corroborates it', function (string $ocr) {
    $service = new DeliveryNoteProcessorService();

    expect($service->isConfirmedFrachtbrief(frachtbriefWithoutOrderNumber(), $ocr))->toBeTrue();
})->with([
    "Rhenus Logistics GmbH\nWOO0000006176714\n",
    "Frachtbrief\nWOO0000006176714\n",
]);

it('rejects a bare weborder number without any corroboration', function () {
    $service = new DeliveryNoteProcessorService();

    expect($service->isConfirmedFrachtbrief(frachtbriefWithoutOrderNumber(), "Lieferschein\nWOO0000006176714\n"))
        ->toBeFalse();
});

it('rejects weborder-shaped noise that is not a real weborder number', function (string $ocr) {
    $service = new DeliveryNoteProcessorService();

    expect($service->isConfirmedFrachtbrief(frachtbriefWithoutOrderNumber(), $ocr))->toBeFalse();
})->with([
    "Rhenus\nWebOrdernr. WOO12\n",            // too few digits, even when labelled
    "Rhenus\nWebOrdernr. WOOABCDEF\n",        // no digits at all
    "Rhenus\nWOO12345\n",                     // bare and too short
    "Frachtbrief\nSchraubenWOO0000006176714", // not a standalone token
]);

it('accepts a weborder number the agent extracted itself', function () {
    $service = new DeliveryNoteProcessorService();

    $content = $service->normalizeFrachtbriefContent(json_decode(frachtbriefJson([
        'order_number' => ['value' => 'WOO0000006176714', 'confidence' => 0.97],
    ])));

    // No supporting OCR text at all — the extracted value alone is evidence,
    // exactly as it already is for the numeric format.
    expect($service->isConfirmedFrachtbrief($content, 'nothing useful here'))->toBeTrue();
});

it('still requires the agent verdict and the threshold for a weborder document', function (array $overrides) {
    $service = new DeliveryNoteProcessorService();

    $content = $service->normalizeFrachtbriefContent(json_decode(frachtbriefJson(array_replace([
        'order_number' => ['value' => null, 'confidence' => null],
    ], $overrides))));

    expect($service->isConfirmedFrachtbrief($content, "Rhenus\nWebOrdernr. WOO0000006176714\n"))->toBeFalse();
})->with([
    'agent says no' => [['is_frachtbrief' => false]],
    'confidence below threshold' => [['document_confidence' => 0.5]],
    'confidence missing' => [['document_confidence' => null]],
]);

it('keeps the numeric order number when a document carries both formats', function () {
    $ocr = "Order Nummer: 260630-01\nWebOrdernr. WOO0000006176714\nEmpfaenger: Musterfirma GmbH\n";

    $processor = (new FakeAgentProcessor())
        ->withOcr($ocr)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson()));

    $result = $processor->run(storedProcess());

    expect($result->frachtbrief_order_number)->toBe('260630-01')
        ->and(basename($result->target_file_path))->toBe('FB_musterfirma-gmbh_2026-06-30_260630-01.pdf')
        ->and($result->target_file_path)->not->toContain('WOO0000006176714');
});

it('does not intercept a delivery note that merely mentions a WOO token', function () {
    // The agent hallucinates a Frachtbrief, but the only WOO-ish evidence is a
    // bare token on an ordinary Lieferschein — the PHP gate vetoes it and the
    // untouched production-order → delivery-note flow runs as before.
    $processor = (new FakeAgentProcessor())
        ->withOcr(DELIVERY_NOTE_OCR . "\nUnsere Referenz WOO0000006176714\n")
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'order_number' => ['value' => null, 'confidence' => null],
        ])))
        ->withResponse(ProductionOrderAgent::class, agentMessage(productionOrderJson(false)))
        ->withResponse(DeliveryNoteAgent::class, agentMessage(deliveryNoteJson()));

    $result = $processor->run(storedProcess());

    expect($processor->agentCalls)
        ->toBe([FrachtbriefAgent::class, ProductionOrderAgent::class, DeliveryNoteAgent::class])
        ->and($result->document_type)->toBeNull()
        ->and(basename($result->target_file_path))->toBe('ls_dn99_acme-logistics.pdf');
});

/*
| Full-pipeline coverage for the Rhenus WebOrder case, mirroring the existing
| complete-frachtbrief test.
*/

it('processes a rhenus weborder frachtbrief end to end', function () {
    $ocr = "Rhenus Logistics GmbH\nWebOrdernr. WOO0000006176714\nEmpfaenger: Rhenus Logistics GmbH\nAbholdatum: 31.07.2026\n";

    $processor = (new FakeAgentProcessor())
        ->withOcr($ocr)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'order_number' => ['value' => 'WOO0000006176714', 'confidence' => 0.97],
            'pickup_date' => ['value' => '31.07.2026', 'confidence' => 0.9],
            'recipient_company' => ['value' => 'Rhenus Logistics GmbH', 'confidence' => 0.95],
        ])));

    $result = $processor->run(storedProcess());

    $expected = config('delivery_note_processor.frachtbrief_folder')
        . '/Rhenus/FB_rhenus-logistics-gmbh_2026-07-31_WOO0000006176714.pdf';

    expect($processor->agentCalls)->toBe([FrachtbriefAgent::class])
        ->and($result->document_type)->toBe(DeliveryNoteProcessorService::DOCUMENT_TYPE_FRACHTBRIEF)
        ->and($result->status)->toBe('finished')
        ->and($result->frachtbrief_order_number)->toBe('WOO0000006176714')
        ->and((float)$result->frachtbrief_order_number_percent)->toBe(0.97)
        ->and($result->frachtbrief_pickup_date)->toBe('2026-07-31')
        ->and($result->target_file_path)->toBe($expected)
        ->and(Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->exists($expected))->toBeTrue();
});

it('keeps the weborder number when the recipient company is missing', function () {
    $ocr = "WebOrdernr. WOO0000006176714\nAbholdatum unbekannt\n";

    $processor = (new FakeAgentProcessor())
        ->withOcr($ocr)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'order_number' => ['value' => 'WOO0000006176714', 'confidence' => 0.97],
            'pickup_date' => ['value' => null, 'confidence' => null],
            'recipient_company' => ['value' => null, 'confidence' => null],
        ])));

    $result = $processor->run(storedProcess());

    $expected = config('delivery_note_processor.frachtbrief_folder')
        . '/Sonstige/FB_xxxxxx_xxxxxx_WOO0000006176714_20260724143025.pdf';

    expect($result->document_type)->toBe(DeliveryNoteProcessorService::DOCUMENT_TYPE_FRACHTBRIEF)
        ->and($result->frachtbrief_order_number)->toBe('WOO0000006176714')
        ->and($result->frachtbrief_recipient_company)->toBeNull()
        ->and($result->frachtbrief_pickup_date)->toBeNull()
        ->and($result->target_file_path)->toBe($expected)
        ->and(Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->exists($expected))->toBeTrue();
});

it('lowercases and canonicalizes a weborder number the agent returned in lowercase', function () {
    $processor = (new FakeAgentProcessor())
        ->withOcr("WebOrdernr. WOO0000006176714\n")
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'order_number' => ['value' => 'woo0000006176714', 'confidence' => 0.97],
            'pickup_date' => ['value' => null, 'confidence' => null],
            'recipient_company' => ['value' => null, 'confidence' => null],
        ])));

    $result = $processor->run(storedProcess());

    expect($result->frachtbrief_order_number)->toBe('WOO0000006176714')
        ->and(basename($result->target_file_path))
        ->toBe('FB_xxxxxx_xxxxxx_WOO0000006176714_20260724143025.pdf');
});

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

/*
| Scenario 1 — an order number alone must NEVER become a pickup date.
|
| This is the regression guard for the bug: `260630-01` looks like it encodes
| 2026-06-30, and the implementation used to act on that. It must not.
*/
it('leaves the pickup date null when only an order number is present', function () {
    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'recipient_company' => ['value' => null, 'confidence' => null],
            'pickup_date' => ['value' => null, 'confidence' => null],
        ])));

    $result = $processor->run(storedProcess());
    $filename = basename($result->target_file_path);

    expect($result->frachtbrief_pickup_date)->toBeNull()
        ->and($result->frachtbrief_pickup_date_percent)->toBeNull()
        ->and($result->frachtbrief_order_number)->toBe('260630-01')
        ->and($filename)->toBe('FB_xxxxxx_xxxxxx_260630-01_20260724143025.pdf')
        // The derived date must appear nowhere.
        ->and($filename)->not->toContain('2026-06-30')
        ->and($result->frachtbrief_pickup_date)->not->toBe('2026-06-30');
});

it('does not derive a pickup date even when the confidence was supplied for a null value', function () {
    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'pickup_date' => ['value' => null, 'confidence' => 0.9],
        ])));

    $result = $processor->run(storedProcess());

    expect($result->frachtbrief_pickup_date)->toBeNull()
        ->and($result->frachtbrief_pickup_date_percent)->toBeNull()
        ->and(basename($result->target_file_path))
        ->toBe('FB_musterfirma-gmbh_xxxxxx_260630-01_20260724143025.pdf');
});

/*
| Scenario 2 — an explicitly stated Abholdatum is still normalized and used.
*/
it('uses and normalizes an explicitly extracted abholdatum', function () {
    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'recipient_company' => ['value' => null, 'confidence' => null],
            'pickup_date' => ['value' => '30.06.2026', 'confidence' => 0.92],
        ])));

    $result = $processor->run(storedProcess());

    expect($result->frachtbrief_pickup_date)->toBe('2026-06-30')
        ->and((float)$result->frachtbrief_pickup_date_percent)->toBe(0.92)
        ->and(basename($result->target_file_path))
        ->toBe('FB_xxxxxx_2026-06-30_260630-01_20260724143025.pdf');
});

it('keeps an explicit pickup date that disagrees with the order-number digits', function () {
    // The order number starts 260630; the document says 2026-07-02. The
    // document wins because the order number is not a date source at all.
    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'pickup_date' => ['value' => '2026-07-02', 'confidence' => 0.9],
        ])));

    $result = $processor->run(storedProcess());

    expect($result->frachtbrief_pickup_date)->toBe('2026-07-02')
        ->and(basename($result->target_file_path))->toBe('FB_musterfirma-gmbh_2026-07-02_260630-01.pdf');
});

/*
| Scenario 3 — an invalid explicit date must NOT fall back to derivation.
*/
it('falls back to the placeholder, not the order number, for an impossible abholdatum', function () {
    $processor = (new FakeAgentProcessor())
        ->withOcr(FRACHTBRIEF_OCR)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'recipient_company' => ['value' => null, 'confidence' => null],
            // 31 February does not exist
            'pickup_date' => ['value' => '31.02.2026', 'confidence' => 0.88],
        ])));

    $result = $processor->run(storedProcess());
    $filename = basename($result->target_file_path);

    expect($result->frachtbrief_pickup_date)->toBeNull()
        ->and($result->frachtbrief_pickup_date_percent)->toBeNull()
        ->and($filename)->toBe('FB_xxxxxx_xxxxxx_260630-01_20260724143025.pdf')
        ->and($filename)->not->toContain('2026-06-30');
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
            // An order number whose digits are not date-shaped at all — the
            // placeholder outcome must be identical to the date-shaped case.
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

    // "Musterfirma GmbH" matches neither carrier → Sonstige subfolder.
    $expected = config('delivery_note_processor.frachtbrief_folder')
        . '/Sonstige/FB_musterfirma-gmbh_2026-06-30_260630-01.pdf';

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
        ->and($destination['folder'])->toBe(config('delivery_note_processor.frachtbrief_folder') . '/Sonstige')
        ->and($destination['subfolder'])->toBe('Sonstige')
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

/*
|--------------------------------------------------------------------------
| Frachtbrief subfolder routing (Rhenus / UPS / Sonstige)
|--------------------------------------------------------------------------
*/

it('routes Rhenus recipient companies to the Rhenus subfolder', function (string $company) {
    $service = new DeliveryNoteProcessorService();

    expect($service->resolveFrachtbriefSubfolder($company))->toBe('Rhenus');
})->with([
    'Rhenus',
    'Rhenus Logistics GmbH',
    'RHENUS WAREHOUSING',
    'Rhenus Warehousing Solutions GmbH',
    'RHENUS SE & Co. KG',
    'rhenus-logistics',                 // resolved slug shape
]);

it('routes UPS recipient companies to the UPS subfolder', function (string $company) {
    $service = new DeliveryNoteProcessorService();

    expect($service->resolveFrachtbriefSubfolder($company))->toBe('UPS');
})->with([
    'UPS',
    'UPS Deutschland',
    'ups logistics',
    'United Parcel Service (UPS)',
    'ups-deutschland',                  // resolved slug shape
]);

it('routes everything else to the Sonstige subfolder', function (?string $company) {
    $service = new DeliveryNoteProcessorService();

    expect($service->resolveFrachtbriefSubfolder($company))->toBe('Sonstige');
})->with([
    'DHL',
    'Musterfirma GmbH',
    'xxxxxx',                           // filename placeholder
    null,                               // missing company
    '',                                 // empty company
]);

it('resolves a Rhenus destination inside the frachtbrief base folder', function () {
    $service = new DeliveryNoteProcessorService();

    $process = new Process();
    $process->forceFill(['source_file_path' => 'source/scan.pdf']);

    $destination = $service->resolveFrachtbriefDestination($process, 'rhenus-logistics', '2026-07-27', '260727-01');

    expect($destination['is_unknown'])->toBeFalse()
        ->and($destination['subfolder'])->toBe('Rhenus')
        ->and($destination['folder'])->toBe(config('delivery_note_processor.frachtbrief_folder') . '/Rhenus')
        ->and($destination['filename'])->toBe('FB_rhenus-logistics_2026-07-27_260727-01.pdf');
});

it('resolves a UPS destination inside the frachtbrief base folder', function () {
    $service = new DeliveryNoteProcessorService();

    $process = new Process();
    $process->forceFill(['source_file_path' => 'source/scan.pdf']);

    $destination = $service->resolveFrachtbriefDestination($process, 'ups-deutschland', '2026-07-27', '260727-02');

    expect($destination['is_unknown'])->toBeFalse()
        ->and($destination['subfolder'])->toBe('UPS')
        ->and($destination['folder'])->toBe(config('delivery_note_processor.frachtbrief_folder') . '/UPS')
        ->and($destination['filename'])->toBe('FB_ups-deutschland_2026-07-27_260727-02.pdf');
});

it('falls back to the raw recipient name for routing when no canonical name resolved', function () {
    // The canonical resolver returned null (carrier absent from the company
    // table), so the filename company segment is the xxxxxx placeholder — but
    // the raw extracted name still routes the file to the Rhenus subfolder.
    $service = new DeliveryNoteProcessorService();

    $process = new Process();
    $process->forceFill(['source_file_path' => 'source/scan.pdf']);

    $destination = $service->resolveFrachtbriefDestination($process, null, '2026-07-27', '260727-05', 'Rhenus Logistics GmbH');

    expect($destination['subfolder'])->toBe('Rhenus')
        ->and($destination['folder'])->toBe(config('delivery_note_processor.frachtbrief_folder') . '/Rhenus')
        // xxxxxx company segment → uniqueness timestamp appended by the renamer.
        ->and($destination['filename'])->toBe('FB_xxxxxx_2026-07-27_260727-05_20260724143025.pdf');
});

it('routes a missing recipient company to the Sonstige subfolder', function () {
    $service = new DeliveryNoteProcessorService();

    $process = new Process();
    $process->forceFill(['source_file_path' => 'source/scan.pdf']);

    $destination = $service->resolveFrachtbriefDestination($process, null, '2026-07-27', '260727-06', null);

    expect($destination['subfolder'])->toBe('Sonstige')
        ->and($destination['folder'])->toBe(config('delivery_note_processor.frachtbrief_folder') . '/Sonstige')
        ->and($destination['filename'])->toBe('FB_xxxxxx_2026-07-27_260727-06_20260724143025.pdf');
});

it('keeps a Frachtbrief without an order number in the unknown folder, not Sonstige', function () {
    // Even for a Rhenus recipient, a missing order number routes to the
    // existing unknown folder — subfolder routing must NOT apply here.
    $service = new DeliveryNoteProcessorService();

    $process = new Process();
    $process->forceFill(['source_file_path' => 'source/scan.pdf']);

    $destination = $service->resolveFrachtbriefDestination($process, 'rhenus-logistics', '2026-07-27', null, 'Rhenus Logistics GmbH');

    expect($destination['is_unknown'])->toBeTrue()
        ->and($destination['folder'])->toBe(config('delivery_note_processor.unknown_folder'))
        ->and($destination['folder'])->not->toContain('Sonstige');
});

/*
| Full-pipeline destination checks for the two carriers, mirroring the existing
| "writes a complete frachtbrief into the configured frachtbrief folder" test.
*/

it('writes a Rhenus frachtbrief into the Rhenus subfolder with an unchanged filename', function () {
    $ocr = "Order Nummer: 260630-01\nEmpfaenger: Rhenus Logistics GmbH\nAbholdatum: 30.06.2026\n";

    $processor = (new FakeAgentProcessor())
        ->withOcr($ocr)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'recipient_company' => ['value' => 'Rhenus Logistics GmbH', 'confidence' => 0.95],
        ])));

    $result = $processor->run(storedProcess());

    $folder = config('delivery_note_processor.frachtbrief_folder') . '/Rhenus';

    expect($result->document_type)->toBe(DeliveryNoteProcessorService::DOCUMENT_TYPE_FRACHTBRIEF)
        ->and(dirname($result->target_file_path))->toBe($folder)
        ->and(basename($result->target_file_path))->toBe('FB_rhenus-logistics-gmbh_2026-06-30_260630-01.pdf')
        ->and(Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->exists($result->target_file_path))->toBeTrue();
});

it('writes a UPS frachtbrief into the UPS subfolder with an unchanged filename', function () {
    $ocr = "Order Nummer: 260630-01\nEmpfaenger: UPS Deutschland\nAbholdatum: 30.06.2026\n";

    $processor = (new FakeAgentProcessor())
        ->withOcr($ocr)
        ->withResponse(FrachtbriefAgent::class, agentMessage(frachtbriefJson([
            'recipient_company' => ['value' => 'UPS Deutschland', 'confidence' => 0.95],
        ])));

    $result = $processor->run(storedProcess());

    $folder = config('delivery_note_processor.frachtbrief_folder') . '/UPS';

    expect($result->document_type)->toBe(DeliveryNoteProcessorService::DOCUMENT_TYPE_FRACHTBRIEF)
        ->and(dirname($result->target_file_path))->toBe($folder)
        ->and(basename($result->target_file_path))->toBe('FB_ups-deutschland_2026-06-30_260630-01.pdf')
        ->and(Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->exists($result->target_file_path))->toBeTrue();
});
