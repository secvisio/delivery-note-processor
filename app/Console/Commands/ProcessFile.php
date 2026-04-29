<?php

namespace App\Console\Commands;

use App\Services\DeliveryNoteProcessorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessFile extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:process-file {filename}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listen to a given directory for arriving files';


    /**
     * @return void
     */
    public function handle(): void
    {

        $filename = $this->argument('filename');

        $sourcePath = config('delivery_note_processor.source_folder') . DIRECTORY_SEPARATOR . $filename;

        if (!Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->exists($sourcePath)) {
            $message = 'Watcher error: ' . $sourcePath . ' does not exists';

            Log::error('WatchDirectory failed', [
                'exception' => $message,
            ]);
        }

        $hash = hash_file(
            'sha256',
            Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->path($sourcePath)
        );

        $fileSize = Storage::disk(config('delivery_note_processor.delivery_notes_disk'))->size($sourcePath);

        $deliveryNoteProcessorService = new DeliveryNoteProcessorService();
        $deliveryNoteProcessorService->fileArrived($sourcePath, $hash, $fileSize);

        $this->info('Send file ' . $sourcePath . ' to DeliveryNoteProcessorService for further processing');

    }
}
