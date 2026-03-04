<?php

namespace App\Jobs;

use App\Models\Process;
use App\Services\DeliveryNoteProcessorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessDeliveryNoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int $processId
     */
    public function __construct(
        public int $processId
    ) {}

    /**
     * @return void
     * @throws \Throwable
     */
    public function handle(): void
    {
        $process = Process::findOrFail($this->processId);

        $process->update([
            'status' => 'running',
        ]);

        try {

            app(DeliveryNoteProcessorService::class)
                ->setTargetPath(config('delivery_note_processor.target_folder'))
                ->run($process);

        } catch (\Throwable $e) {
            $process->update([
                'status' => 'failed',
                'failed_message' => $e->getMessage(),
                'failed_trace' => $e->getTraceAsString(),
            ]);

        }
    }
}
