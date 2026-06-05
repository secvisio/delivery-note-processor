<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SourcePath extends Command
{
    /**
     * Print the absolute filesystem path of the delivery-note source folder.
     *
     * Used by docker/php/watch-delivery.sh so the inotify watcher always
     * watches the same disk + source_folder the upload/processor use.
     *
     * @var string
     */
    protected $signature = 'delivery-note:source-path';

    /**
     * @var string
     */
    protected $description = 'Output the absolute path of the configured delivery-note source folder';

    /**
     * @return void
     */
    public function handle(): void
    {
        $path = Storage::disk(config('delivery_note_processor.delivery_notes_disk'))
            ->path(config('delivery_note_processor.source_folder'));

        $this->output->write(rtrim($path, '/'));
    }
}
