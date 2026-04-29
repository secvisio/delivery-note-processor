<?php
declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessDeliveryNoteJob;
use App\Models\Process;
use App\Neuron\DeliveryNoteAgent;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Spatie\PdfToImage\Exceptions\PdfDoesNotExist;
use stdClass;
use thiagoalessio\TesseractOCR\TesseractOCR;
use thiagoalessio\TesseractOCR\TesseractOcrException;
use Throwable;

class DeliveryNoteProcessorService
{
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
     * @var float
     */
    protected float $threshold = 0.85;

    /**
     * @var int
     */
    protected int $maxOcrChatToSave = 16383;

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

        if ($this->isPdf($process->source_file_path)) {

            try {
                $fileConverter = new FileConverterService(config('delivery_note_processor.target_folder'));
                $fileToProcess = $fileConverter->run($process->source_file_path, 'pdf', 'jpg');
            } catch (Exception $e) {
                $process->update([
                    'status' => 'failed',
                    'failed_message' => $e->getMessage(),
                    'failed_trace' => $e->getTraceAsString(),
                ]);

                Log::error($e->getMessage() . ' - ' . $e->getTraceAsString());

                return $process;
            }

        } else {
            $fileToProcess = $process->source_file_path;
        }

        $ocrContent = $this->runOCR($fileToProcess);
        if (!empty($ocrContent)) {

            $process->update([
                'ocr_result' => Str::limit($ocrContent, $this->getMaxOcrChatToSave(), ''),
            ]);

            $message = $this->runAgent($this->getAgentPrompt(), $ocrContent);
            $messageContent = $this->getObjectFromContent(json_decode($message->getContent()));

            $generatedFilename = $this->generateFilename($process, $messageContent, $this->getThreshold());

            $updatedProcess = $this->updateProcess($process, $message, $messageContent, $generatedFilename);

            try {

                $saveToFolder = config('delivery_note_processor.target_folder');

                if (str_contains($generatedFilename, __('default.unknown_company')) || str_contains($generatedFilename, __('default.unknown_id'))) {
                    $saveToFolder = config('delivery_note_processor.unknown_folder');
                }

                $this->disk->copy(
                    $updatedProcess->source_file_path,
                    $saveToFolder . DIRECTORY_SEPARATOR . $generatedFilename
                );
            } catch (Exception $e) {
                $updatedProcess->update([
                    'status' => 'failed',
                    'failed_message' => $e->getMessage(),
                    'failed_trace' => $e->getTraceAsString(),
                ]);

                Log::error($e->getMessage() . ' - ' . $e->getTraceAsString());
            }

            return $updatedProcess;
        }

        return $process;

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

        $fileExtension = Str::afterLast($process->source_file_path, '.');

        $company = $messageContent->company;
        $deliveryNote = $messageContent->deliveryNote;
        $invoice = $messageContent->invoice;

        if ($company?->percent < $threshold || null === $company?->name) {
            $company->name = __('default.unknown_company');
        }

        return match (true) {
            $deliveryNote?->percent >= $threshold && null !== $deliveryNote?->id =>
                $this->generateDeliveryNoteFileName($company->name, $deliveryNote->id, $fileExtension),

//            $invoice?->percent >= $threshold && null !== $invoice?->id =>
//                $this->generateInvoiceFileName($company->name, $invoice->id, $fileExtension),

            default => $this->noMatch($fileExtension),
        };

    }

    /**
     * @param string $sourcePath
     * @param string $hash
     * @param int $filesize
     * @return void
     */
    public function fileArrived(string $sourcePath, string $hash, int $filesize): void
    {
        $process = $this->createProcess($sourcePath, $hash, $filesize);
        ProcessDeliveryNoteJob::dispatch($process->id)->onQueue('default');
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
     * @param $file
     * @return bool
     */
    private function isPdf($file): bool
    {
        $file = $this->disk->path($file);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($fileInfo, $file);

        if ($extension === 'pdf' && $mime === 'application/pdf') {
            return true;
        }

        return false;
    }

    /**
     * @param string $imageFile
     * @return string
     * @throws TesseractOcrException
     */
    private function runOCR(string $imageFile): string
    {
        $absolutePath = $this->disk->path($imageFile);
        $tesseractOcr = new TesseractOCR($absolutePath);
        return $tesseractOcr->run();
    }

    /**
     * @param string $prompt
     * @param string $ocrContent
     * @return Message
     * @throws Throwable
     */
    private function runAgent(string $prompt, string $ocrContent): Message
    {
        $prompt = str_replace('{{ $ocrContent }}', $ocrContent, $prompt);
        return DeliveryNoteAgent::make()->chat(new UserMessage($prompt));
    }

    /**
     * @param string $companyName
     * @param string $deliveryNoteId
     * @param string $fileExtension
     * @return string
     */
    private function generateDeliveryNoteFileName(string $companyName, string $deliveryNoteId, string $fileExtension): string
    {
        return sprintf(
            '%s_ls_%s.%s',
            Str::snake(Str::lower($companyName)),
            Str::snake(Str::lower($deliveryNoteId)),
            $fileExtension
        );
    }

    /**
     * @param string $companyName
     * @param string $invoiceId
     * @param string $fileExtension
     * @return string
     */
    private function generateInvoiceFileName(string $companyName, string $invoiceId, string $fileExtension): string
    {
        return sprintf(
            '%s_re_%s.%s',
            Str::snake(Str::lower($companyName)),
            Str::snake(Str::lower($invoiceId)),
            $fileExtension
        );
    }

    /**
     * @param string $fileExtension
     * @return string
     */
    private function noMatch(string $fileExtension): string
    {
        return sprintf(
            '%s_%s_%s.%s',
            __('default.unknown_company'),
            __('default.unknown_id'),
            Carbon::now()->format('YmdHis'),
            $fileExtension
        );
    }
}
