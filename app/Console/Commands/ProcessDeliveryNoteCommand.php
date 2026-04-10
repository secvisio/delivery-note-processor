<?php

namespace App\Console\Commands;

use App\Services\DeliveryNoteProcessorService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Log;
use Spatie\PdfToImage\Exceptions\PdfDoesNotExist;
use thiagoalessio\TesseractOCR\TesseractOcrException;
use Throwable;

class ProcessDeliveryNoteCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:process-delivery-note';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * @return void
     * @throws PdfDoesNotExist
     * @throws Throwable
     * @throws TesseractOcrException
     */
    public function handle(): void
    {
        $sourceFile = 'source/example_invoice_1.png';
        $targetPath = 'target';

        $processor = new DeliveryNoteProcessorService();
        $processor->setSourcePath($sourceFile)
            ->setTargetPath($targetPath);

        try {
            $process = $processor->run();
            dump($process);
        } catch (FileNotFoundException $e) {
            Log::error($e->getMessage());
        }
    }

}
