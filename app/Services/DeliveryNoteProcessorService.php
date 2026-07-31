<?php
declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessDeliveryNoteJob;
use App\Models\Process;
use App\Neuron\DeliveryNoteAgent;
use App\Neuron\FrachtbriefAgent;
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
    private const FIXED_DELIVERY_NOTES_PATH = '/mnt/laufwerk/Lieferscheine';
    private const FIXED_UNKNOWN_PATH = '/mnt/laufwerk/Nicht zugeordnet';
    private const FIXED_PRODUCTION_ORDERS_PATH = '/mnt/laufwerk/Produktionsaufträge';
    private const FIXED_FRACHTBRIEFE_PATH = '/mnt/laufwerk/Frachtbriefe';

    /**
     * Value written to `processes.document_type` for a confirmed Frachtbrief.
     * The delivery-note and production-order branches intentionally leave the
     * column null, exactly as before this feature existed.
     */
    public const DOCUMENT_TYPE_FRACHTBRIEF = 'frachtbrief';

    /**
     * Customer-specific subfolders below the configured Frachtbrief base folder.
     * A confirmed Frachtbrief that is actually filed into the Frachtbrief
     * destination (i.e. it has an order number) is placed into one of these
     * based on its recipient company name. This routing is deterministic and
     * happens purely in PHP — the OpenAI prompt is not involved.
     */
    public const FRACHTBRIEF_SUBFOLDER_RHENUS = 'Rhenus';
    public const FRACHTBRIEF_SUBFOLDER_UPS = 'UPS';
    public const FRACHTBRIEF_SUBFOLDER_SONSTIGE = 'Sonstige';

    /**
     * Cheap, deterministic pre-check used as CORROBORATION only — never on its
     * own as the classification verdict. Matches an `Order Nummer`-ish label
     * (including the common OCR corruptions `0rder`, `Numner`, `Nr.`, and a
     * missing space) followed by a `YYMMDD-NN` value within a short distance.
     */
    private const FRACHTBRIEF_ORDER_LABEL_PATTERN =
        '/[o0]rder\s*[-]?\s*(?:nummer|numner|nunmer|nr\.?|no\.?)\s*[:.]?\s*\d{6}\s*[-\x{2010}-\x{2015}\x{2212}]?\s*\d{2}/iu';

    /**
     * Secondary, Rhenus-specific corroboration: a `WebOrdernr.` label followed
     * by a `WOO`-prefixed value. The label and the value must appear together,
     * which is what makes this specific enough to stand on its own — the same
     * way the `Order Nummer` label pattern above does. Spacing, hyphenation,
     * the trailing dot/colon and `O`/`0` OCR confusion are all tolerated.
     */
    private const FRACHTBRIEF_WEBORDER_PATTERN =
        '/\bweb\s*[-]?\s*[o0]rder\s*[-]?\s*(?:nr|nummer|no)?\.?\s*:?\s*W[O0]{2}\d{8,20}\b/iu';

    /**
     * A `WOO`-prefixed value WITHOUT its label. Far weaker evidence than the
     * pattern above — a bare token could be a tracking reference quoted on an
     * ordinary Lieferschein — so it is never accepted on its own; see
     * isConfirmedFrachtbrief(), which additionally requires the corroboration
     * pattern below. The digit floor is deliberately higher than in the
     * labelled variant for the same reason.
     */
    private const FRACHTBRIEF_WEBORDER_BARE_PATTERN = '/\bW[O0]{2}\d{10,20}\b/iu';

    /**
     * Optional corroborating terms for a Rhenus WebOrder document. These are
     * NEVER required alongside a correctly labelled WebOrder number — they only
     * unlock the bare, unlabelled variant above.
     */
    private const FRACHTBRIEF_RHENUS_CORROBORATION_PATTERN = '/\b(?:rhenus|frachtbrief)\b/iu';

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
     * Prompt for the Frachtbrief (freight waybill) classification + extraction
     * agent. Runs FIRST, before the production-order agent, because its
     * identifying signals — an `Order Nummer` shaped like `YYMMDD-NN`, or the
     * Rhenus `WebOrdernr.` value shaped like `WOO` + digits — are far more
     * specific than the production-order layout heuristics.
     *
     * The prompt is deliberately strict about NOT treating a generic
     * Bestellnummer/Auftragsnummer as proof: ordinary Lieferscheine routinely
     * carry a customer order reference, and a false positive here would divert
     * a delivery note into the Frachtbriefe folder. When the agent is unsure it
     * must answer false — the processor then falls through to the untouched
     * production-order / delivery-note flow.
     *
     * @var string
     */
    protected string $frachtbriefPrompt = '
            You will receive plain text parsed by an OCR scanner from a scanned document.

            Decide independently:
              (1) whether the document is a Frachtbrief (freight waybill / consignment note)
              (2) which of three values, if any, can be reliably extracted

            PRIMARY IDENTIFYING SIGNAL
            A Frachtbrief carries an "Order Nummer" field whose value looks like YYMMDD-NN,
            for example "260630-01", "260701-15", "260915-03". The label may be corrupted by
            OCR; treat all of these as the same field:
              Order Nummer, Ordernummer, Order Nr., Order-Nr., 0rder Nummer, 0rder Numner

            SECONDARY IDENTIFYING SIGNAL — RHENUS WEBORDER
            Only when NO valid "Order Nummer" in the YYMMDD-NN format above is present,
            a document may ALSO be a Frachtbrief when it carries a WebOrder field whose
            value starts with WOO followed by digits, for example:
              WebOrdernr. WOO0000006176714
              WebOrdernr WOO0000006176714
              WebOrder-Nr. WOO0000006176714
              Web Order Nr. WOO0000006176714
              WebOrdernr.: WOO0000006176714
              Web0rdernr. WOO0000006176714
            A correctly labelled WebOrder value is on its own sufficient evidence for a
            Frachtbrief. If the document additionally contains "Rhenus" or "Frachtbrief",
            that is stronger corroborating evidence and document_confidence may reflect it.

            SUPPORTING SIGNALS (corroboration, not proof on their own)
              Abholdatum, Empfaenger, Empfänger, Absender, Frachtfuehrer, Frachtführer,
              Ladestelle, Entladestelle, CMR

            NEGATIVE RULE — READ CAREFULLY
            A generic "Bestellnummer", "Auftragsnummer", "Kundenauftragsnummer" or a bare
            "Order" reference does NOT make a document a Frachtbrief. Ordinary delivery notes
            (Lieferscheine) and invoices frequently contain a customer order reference. Set
            is_frachtbrief to false unless you find an Order Nummer in the YYMMDD-NN shape, or
            a labelled WebOrder number as described above, or the document is otherwise
            unmistakably a freight waybill. A bare "WOO..." token without a WebOrder label is
            NOT enough on its own.

            VALUES TO EXTRACT
              - order_number      : the Order Nummer, keep the hyphen, e.g. "260630-01".
                                    When — and only when — no Order Nummer in the YYMMDD-NN
                                    format is present, return the complete WebOrder number
                                    INCLUDING its WOO prefix instead, e.g. "WOO0000006176714".
                                    Never invent, derive, shorten or drop the WOO prefix.
              - pickup_date       : the Abholdatum, NORMALIZED to YYYY-MM-DD
                                    ("30.06.2026" and "30/06/2026" both become "2026-06-30")
              - recipient_company : the receiving company (Firma / Empfaengername)

            PICKUP DATE — STRICT SOURCE RULE
            Return a pickup_date ONLY when the document explicitly states a pickup /
            collection date under a label that clearly means exactly that, such as:
              Abholdatum, Abhol-Datum, Datum der Abholung, Pickup Date, Collection Date

            NEVER produce a pickup_date from any of the following:
              - the order number, in EITHER format. The leading digits of "260630-01" may
                look like a date, and a WebOrder number such as "WOO0000006176714" is
                nothing but digits, but they are part of the order number and nothing
                else. Do NOT convert them, and do NOT let them influence pickup_date in
                any way.
              - a document creation date, print date, invoice date or delivery date
              - the current date, or a date taken from the file name
              - any other guess or inference

            If no explicit pickup date is present, return null for BOTH pickup_date.value
            and pickup_date.confidence. A missing pickup date is a normal, expected case.

            RULES
              - Allow minor OCR mistakes (O vs 0, I vs 1, missing colons or whitespace).
              - Do NOT invent, guess, complete or enrich values. In particular, never use
                outside knowledge to expand or correct a company name — use only text that is
                actually present in the OCR result.
              - If a value cannot be confidently extracted, return null for BOTH its "value"
                and its "confidence".
              - is_frachtbrief can be true even when some values are unreadable, as long as the
                layout/structure/keywords clearly indicate a Frachtbrief.
              - Every confidence is a number between 0 and 1, up to 2 digits after the dot
                (1 = 100% certain, 0.15 = 15% certain).
              - "document_confidence" is your certainty about the is_frachtbrief decision.
                Use a low value when is_frachtbrief is false.

            Return a single JSON object with EXACTLY these keys, no markdown, no extra prose,
            no comments:

            {"is_frachtbrief": true|false, "document_confidence": 0.96, "order_number": {"value": "260630-01", "confidence": 0.98}, "pickup_date": {"value": "2026-06-30", "confidence": 0.92}, "recipient_company": {"value": "Musterfirma GmbH", "confidence": 0.91}}

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

    /**
     * Resolves raw LLM company names to a stable canonical filename slug.
     *
     * @var CompanyResolverService
     */
    protected CompanyResolverService $companyResolver;

    public function __construct(?CompanyResolverService $companyResolver = null)
    {
        $this->disk = Storage::disk(config('delivery_note_processor.delivery_notes_disk'));
        $this->companyResolver = $companyResolver ?? new CompanyResolverService();
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

            // Frachtbrief is checked FIRST because its identifying signals (an
            // `Order Nummer` shaped like YYMMDD-NN, or a Rhenus `WebOrdernr.`
            // shaped like WOO + digits) are much more specific than the
            // production-order heuristics. Every failure mode of this block
            // — agent exception, malformed JSON, low confidence, no order-number
            // evidence — yields null/false and falls through to the untouched
            // production-order and delivery-note flow below.
            $frachtbriefResult = $this->extractFrachtbrief($ocrContent);
            if ($frachtbriefResult !== null
                && $this->isConfirmedFrachtbrief($frachtbriefResult['content'], $ocrContent)) {
                return $this->handleFrachtbrief(
                    $process,
                    $frachtbriefResult['message'],
                    $frachtbriefResult['content'],
                    $ocrContent
                );
            }

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
        $messageContent = $this->getObjectFromContent(json_decode($message->getContent())) ?? new stdClass();

        // Resolve the raw LLM company name to a STABLE canonical slug so the
        // same real company always renders identically across scans. A null
        // result means "could not be trusted/matched" and collapses to the
        // xxxxxx placeholder (→ unknown folder) via the same logic as before.
        $resolvedCompanyName = $this->companyResolver->resolve(
            $messageContent->company->name ?? null,
            $this->extractCertainty($messageContent->company ?? null),
            $ocrContent
        );

        $destination = $this->resolveDeliveryDestination($process, $messageContent, $resolvedCompanyName);
        $generatedFilename = $destination['filename'];
        $isUnknown = $destination['is_unknown'];
        $saveToFolder = $destination['folder'];

        $updatedProcess = $this->updateProcess($process, $message, $messageContent, $saveToFolder . DIRECTORY_SEPARATOR . $generatedFilename);

        try {

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
     * Frachtbrief branch. Runs only when isConfirmedFrachtbrief() agreed, i.e.
     * the agent said `is_frachtbrief: true`, its document confidence cleared
     * the threshold, AND an order-number-shaped value was found (either in the
     * extraction or in the raw OCR text).
     *
     * Routing (ad-hoc rules for this feature only — the delivery-note and
     * production-order rules are untouched):
     *   - order number extracted        → Frachtbriefe folder
     *   - order number NOT extracted    → existing unknown folder, because the
     *                                     order number is the document's
     *                                     primary identifier and without it the
     *                                     file cannot be found again
     * A missing company or pickup date does NOT divert the file; those segments
     * collapse to the `xxxxxx` placeholder, mirroring the production-order
     * fallback behaviour.
     */
    private function handleFrachtbrief(
        Process $process,
        Message $message,
        object  $messageContent,
        string  $ocrContent
    ): Process
    {
        // Reuse the existing canonical-company pipeline so OCR variants of the
        // same recipient collapse onto the slug already used for that company
        // elsewhere. A null result means "not trusted/matched" and renders as
        // the xxxxxx placeholder — exactly as in the delivery-note branch.
        $resolvedCompanyName = $this->companyResolver->resolve(
            $messageContent->recipient_company ?? null,
            $messageContent->recipient_company_percent ?? null,
            $ocrContent
        );

        $orderNumber = FilenameNormalizer::sanitizeOrderNumber($messageContent->order_number ?? null);
        $pickupDate = $this->resolveFrachtbriefPickupDate($messageContent);

        $destination = $this->resolveFrachtbriefDestination(
            $process,
            $resolvedCompanyName,
            $pickupDate,
            $orderNumber,
            $messageContent->recipient_company ?? null
        );

        $process->document_type = self::DOCUMENT_TYPE_FRACHTBRIEF;
        // The RAW agent name is persisted (not the canonical slug), matching how
        // the delivery-note branch stores company_name — the slug is visible in
        // the resulting filename.
        $process->frachtbrief_recipient_company = $messageContent->recipient_company ?? null;
        $process->frachtbrief_recipient_company_percent = $messageContent->recipient_company_percent ?? null;
        $process->frachtbrief_order_number = $orderNumber !== '' ? $orderNumber : null;
        $process->frachtbrief_order_number_percent = $messageContent->order_number_percent ?? null;
        $process->frachtbrief_pickup_date = $pickupDate !== '' ? $pickupDate : null;
        // The confidence is only meaningful alongside a usable date: if the
        // Abholdatum was absent or not a real calendar date, the agent's
        // self-reported certainty describes nothing and is dropped with it.
        $process->frachtbrief_pickup_date_percent = $pickupDate !== ''
            ? ($messageContent->pickup_date_percent ?? null)
            : null;
        $process->target_file_path = $destination['folder'] . DIRECTORY_SEPARATOR . $destination['filename'];
        $process->status = 'finished';
        $process->input_token = $message->getUsage()?->inputTokens;
        $process->output_token = $message->getUsage()?->outputTokens;
        $process->total_token = $message->getUsage()?->getTotal();
        $process->save();

        try {
            $this->disk->copy(
                $process->source_file_path,
                $destination['folder'] . DIRECTORY_SEPARATOR . $destination['filename']
            );

            $this->copyToFixedPath(
                $process->source_file_path,
                $destination['is_unknown']
                    ? self::FIXED_UNKNOWN_PATH
                    : self::FIXED_FRACHTBRIEFE_PATH . '/' . $destination['subfolder'],
                $destination['filename']
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
     * Pure routing decision for a Frachtbrief: folder, filename, subfolder and
     * the unknown flag. Extracted so it can be tested without OCR, the agent or
     * the disk — the same seam resolveDeliveryDestination() provides for
     * delivery notes.
     *
     * When the file is actually filed into the Frachtbrief destination (order
     * number present), it is routed into a customer-specific subfolder
     * (Rhenus / UPS / Sonstige) below the configured base folder, decided from
     * the best available recipient company name. A Frachtbrief WITHOUT an order
     * number still goes to the existing unknown folder — the subfolder routing
     * deliberately does not apply there.
     *
     * The company value used for routing prefers the resolved canonical slug
     * (`$resolvedCompanyName`) and falls back to the raw extracted recipient
     * name (`$rawRecipientCompany`) when no canonical match exists, so a
     * recognizable carrier still routes correctly even when it is absent from
     * the company table and the filename shows the `xxxxxx` placeholder.
     *
     * @return array{folder: string, filename: string, is_unknown: bool, subfolder: string}
     */
    public function resolveFrachtbriefDestination(
        Process $process,
        ?string $resolvedCompanyName,
        ?string $pickupDate,
        ?string $orderNumber,
        ?string $rawRecipientCompany = null
    ): array
    {
        $orderNumber = FilenameNormalizer::sanitizeOrderNumber($orderNumber);

        // The order number is the Frachtbrief's primary identifier: without it
        // the file needs a human, so it goes to the existing unknown folder.
        $isUnknown = $orderNumber === '';

        $subfolder = $this->resolveFrachtbriefSubfolder($resolvedCompanyName ?? $rawRecipientCompany);

        return [
            'folder' => $isUnknown
                ? config('delivery_note_processor.unknown_folder')
                : config('delivery_note_processor.frachtbrief_folder') . '/' . $subfolder,
            'filename' => FileRenamingService::generateFrachtbrief(
                $resolvedCompanyName,
                $pickupDate,
                $orderNumber,
                Str::afterLast($process->source_file_path, '.'),
                Carbon::now()->format('YmdHis'),
            ),
            'is_unknown' => $isUnknown,
            'subfolder' => $subfolder,
        ];
    }

    /**
     * Deterministically resolve the customer-specific Frachtbrief subfolder from
     * a recipient company name. Matching is case-insensitive and substring-based:
     *
     *   - contains "Rhenus" → Rhenus
     *   - contains "UPS"    → UPS
     *   - anything else, a missing/empty name, or the `xxxxxx` placeholder
     *                       → Sonstige
     *
     * This is intentionally small and isolated so it can be unit-tested in
     * isolation. It must never call OpenAI — the routing decision is made
     * exclusively in PHP.
     */
    public function resolveFrachtbriefSubfolder(?string $companyName): string
    {
        if ($companyName === null) {
            return self::FRACHTBRIEF_SUBFOLDER_SONSTIGE;
        }

        if (Str::contains($companyName, 'rhenus', ignoreCase: true)) {
            return self::FRACHTBRIEF_SUBFOLDER_RHENUS;
        }

        if (Str::contains($companyName, 'ups', ignoreCase: true)) {
            return self::FRACHTBRIEF_SUBFOLDER_UPS;
        }

        return self::FRACHTBRIEF_SUBFOLDER_SONSTIGE;
    }

    /**
     * Decide the pickup-date filename segment.
     *
     * The ONLY accepted source is an Abholdatum the agent explicitly found in
     * the document. The value is checkdate()-validated, so an impossible date
     * such as `31.02.2026` yields '' and the caller renders the xxxxxx
     * placeholder.
     *
     * DO NOT derive this value from the order number. The six leading digits of
     * an order number like `260630-01` resemble a YYMMDD date, but that
     * resemblance is coincidental — those digits are part of the order number
     * and carry no pickup-date meaning. An earlier version of this method used
     * them as a fallback and produced filenames asserting a pickup date the
     * document never stated. Missing is missing: return '' and let the
     * placeholder say so honestly.
     */
    private function resolveFrachtbriefPickupDate(object $messageContent): string
    {
        return FilenameNormalizer::sanitizePickupDate($messageContent->pickup_date ?? null);
    }

    /**
     * Run the Frachtbrief agent and return the message + parsed content, or
     * null if the response is unusable.
     *
     * Every failure is swallowed into null so a Frachtbrief-detection problem
     * can never break a document that previously processed fine: agent
     * exceptions, non-string content, invalid JSON and structurally invalid
     * payloads all fall through to the existing production-order flow.
     *
     * @return array{message: Message, content: object}|null
     */
    private function extractFrachtbrief(string $ocrContent): ?array
    {
        try {
            $message = $this->runAgent($this->getFrachtbriefPrompt(), $ocrContent, FrachtbriefAgent::class);
        } catch (Throwable $e) {
            Log::warning('Frachtbrief agent failed, falling back to the existing flow: ' . $e->getMessage());
            return null;
        }

        try {
            $raw = $message->getContent();
            $content = is_string($raw) ? $this->normalizeFrachtbriefContent(json_decode($raw)) : null;
        } catch (Throwable $e) {
            Log::warning('Frachtbrief response could not be parsed, falling back to the existing flow: ' . $e->getMessage());
            return null;
        }

        if ($content === null) {
            return null;
        }

        return ['message' => $message, 'content' => $content];
    }

    /**
     * Whether a normalized Frachtbrief payload is trustworthy enough to
     * intercept the document. ALL of the following must hold:
     *
     *   1. `is_frachtbrief` is the boolean true (a "true" STRING is rejected —
     *      normalizeFrachtbriefContent() only accepts real booleans, matching
     *      the existing production-order convention)
     *   2. `document_confidence` is present and at/above the project threshold
     *   3. corroborating evidence for the order number exists — see below
     *
     * The evidence is checked in descending order of specificity, so the
     * original `Order Nummer` signal always decides first and the Rhenus
     * WebOrder signal is only consulted when it found nothing:
     *
     *   a) an extracted value that sanitizes to a usable order number (either
     *      the YYMMDD-NN or the WOO shape)
     *   b) an `Order Nummer`-style label with a YYMMDD-NN value in the raw OCR
     *   c) a `WebOrdernr.`-style label with a WOO value in the raw OCR
     *   d) a bare WOO value in the raw OCR, but ONLY together with `Rhenus` or
     *      `Frachtbrief` — unlabelled, it is too weak on its own
     *
     * Anything less returns false and the document continues through the
     * untouched production-order / delivery-note flow.
     *
     * None of these patterns classifies anything by itself: they can only
     * confirm or veto a decision the agent already made under (1) and (2).
     */
    public function isConfirmedFrachtbrief(?object $messageContent, string $ocrContent): bool
    {
        if ($messageContent === null) {
            return false;
        }

        if (($messageContent->is_frachtbrief ?? null) !== true) {
            return false;
        }

        $confidence = $messageContent->document_confidence ?? null;
        if ($confidence === null || $confidence < $this->getThreshold()) {
            return false;
        }

        if (FilenameNormalizer::sanitizeOrderNumber($messageContent->order_number ?? null) !== '') {
            return true;
        }

        if (preg_match(self::FRACHTBRIEF_ORDER_LABEL_PATTERN, $ocrContent) === 1) {
            return true;
        }

        if (preg_match(self::FRACHTBRIEF_WEBORDER_PATTERN, $ocrContent) === 1) {
            return true;
        }

        return preg_match(self::FRACHTBRIEF_WEBORDER_BARE_PATTERN, $ocrContent) === 1
            && preg_match(self::FRACHTBRIEF_RHENUS_CORROBORATION_PATTERN, $ocrContent) === 1;
    }

    /**
     * Validate the Frachtbrief JSON defensively, mirroring
     * normalizeProductionOrderContent(). The agent's reply is not trusted:
     * only a stdClass (or the first stdClass of an array) carrying a REAL
     * boolean `is_frachtbrief` is accepted — the string "true" is not.
     *
     * The three extracted fields arrive as `{value, confidence}` objects; a
     * bare scalar is tolerated as the value with a null confidence. Values are
     * coerced to string|null and confidences to a float in [0,1] or null, so
     * downstream code can rely on the shape.
     */
    public function normalizeFrachtbriefContent(mixed $content): ?object
    {
        $object = $this->getObjectFromContent($content);
        if ($object === null) {
            return null;
        }

        if (!property_exists($object, 'is_frachtbrief') || !is_bool($object->is_frachtbrief)) {
            return null;
        }

        $normalized = new stdClass();
        $normalized->is_frachtbrief = $object->is_frachtbrief;
        $normalized->document_confidence = $this->coerceConfidence($object->document_confidence ?? null);

        foreach (['order_number', 'pickup_date', 'recipient_company'] as $field) {
            $node = $object->{$field} ?? null;

            $normalized->{$field} = $this->coerceNullableString($this->extractFieldValue($node));
            $normalized->{$field . '_percent'} = $this->coerceConfidence($this->extractFieldConfidence($node));
        }

        return $normalized;
    }

    /**
     * Pull the `value` out of a `{value, confidence}` node, tolerating an agent
     * that emitted a bare scalar instead of the documented object shape.
     */
    private function extractFieldValue(mixed $node): mixed
    {
        if ($node instanceof stdClass) {
            return $node->value ?? null;
        }

        if (is_string($node) || is_int($node) || is_float($node)) {
            return $node;
        }

        return null;
    }

    private function extractFieldConfidence(mixed $node): mixed
    {
        return $node instanceof stdClass ? ($node->confidence ?? null) : null;
    }

    /**
     * Coerce a self-reported certainty into a float within [0,1], or null when
     * it is absent, non-numeric or out of range. Out-of-range values are
     * treated as unusable rather than clamped: a model that answers "96" for a
     * 0..1 field is not reliably saying 96%.
     */
    private function coerceConfidence(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $confidence = (float)$value;

        if ($confidence < 0 || $confidence > 1) {
            return null;
        }

        return $confidence;
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
     * Like buildFilenameData(), but the company segment is the already-resolved
     * canonical slug from CompanyResolverService rather than the threshold-gated
     * raw LLM name. The delivery-note id is still threshold-gated exactly as
     * before. A null $resolvedCompanyName collapses to the xxxxxx placeholder,
     * which (via FileRenamingService::isFallback) routes the file to the
     * unknown folder — the existing rule that company OR id missing → unknown.
     */
    private function buildResolvedFilenameData(Process $process, object $messageContent, float $threshold, ?string $resolvedCompanyName): array
    {
        $deliveryNote = $messageContent->deliveryNote ?? null;
        $deliveryNoteId = ($deliveryNote?->percent >= $threshold) ? ($deliveryNote->id ?? null) : null;

        return [
            'delivery_note_id' => $deliveryNoteId,
            'company_name' => $resolvedCompanyName,
            'extension' => Str::afterLast($process->source_file_path, '.'),
            'timestamp' => Carbon::now()->format('YmdHis'),
        ];
    }

    /**
     * Pure routing decision for a delivery-note extraction: given the LLM
     * payload and the already-resolved canonical company name, compute the
     * destination folder, the final filename, and whether the file is
     * "unknown" (needs human review). Extracted from handleDeliveryNote() so
     * the routing can be tested without running OCR or the LLM agent.
     *
     * Folder rule (unchanged): the file goes to unknown_folder whenever the
     * delivery-note id OR the company could not be confidently determined;
     * otherwise it goes to target_folder.
     *
     * @return array{folder: string, filename: string, is_unknown: bool}
     */
    public function resolveDeliveryDestination(Process $process, object $messageContent, ?string $resolvedCompanyName): array
    {
        $filenameData = $this->buildResolvedFilenameData($process, $messageContent, $this->getThreshold(), $resolvedCompanyName);

        $isUnknown = FileRenamingService::isFallback($filenameData);

        return [
            'folder' => $isUnknown
                ? config('delivery_note_processor.unknown_folder')
                : config('delivery_note_processor.target_folder'),
            'filename' => FileRenamingService::generate($filenameData),
            'is_unknown' => $isUnknown,
        ];
    }

    /**
     * Coerce the LLM's `percent` certainty (delivered as a string such as
     * "0.9") into a float, or null when it is absent/unparseable.
     */
    private function extractCertainty(?object $node): ?float
    {
        $percent = $node->percent ?? null;

        if ($percent === null || $percent === '' || !is_numeric($percent)) {
            return null;
        }

        return (float)$percent;
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
     * @return string
     */
    public function getFrachtbriefPrompt(): string
    {
        return $this->frachtbriefPrompt;
    }

    /**
     * @param string $frachtbriefPrompt
     * @return $this
     */
    public function setFrachtbriefPrompt(string $frachtbriefPrompt): self
    {
        $this->frachtbriefPrompt = $frachtbriefPrompt;
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
     * @param string $targetFilePath relative destination path (folder + filename) where the file is actually saved
     * @return Process
     */
    private function updateProcess(Process $process, Message $message, object $messageContent, string $targetFilePath): Process
    {
        $process->target_file_path = $targetFilePath;
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
     * Visibility is `protected` (not private) purely so tests can substitute a
     * canned OCR result and exercise the branch routing without a real
     * Tesseract binary. Behaviour is unchanged.
     *
     * @throws TesseractOcrException
     */
    protected function runOCR(string $imageFile): string
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
     * Visibility is `protected` (not private) purely so tests can substitute
     * canned agent responses instead of calling the real OpenAI API.
     * Behaviour is unchanged.
     *
     * @param class-string<Agent> $agentClass
     * @return Message
     * @throws Throwable
     */
    protected function runAgent(string $prompt, string $ocrContent, string $agentClass = DeliveryNoteAgent::class): Message
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
