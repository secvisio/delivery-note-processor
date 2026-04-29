<?php

namespace App\Jobs;

use App\Models\Process;
use App\Services\DeliveryNoteProcessorService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessDeliveryNoteJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    /**
     * @param int $processId
     */
    public function __construct(
        public int $processId
    ) {}

    /**
     * Lock key for ShouldBeUnique. Prevents two workers from ever running
     * the same processId concurrently, even if Redis re-releases the job
     * after retry_after.
     */
    public function uniqueId(): string
    {
        return 'process_delivery_note_' . $this->processId;
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function handle(): void
    {
        $process = Process::findOrFail($this->processId);

        if (in_array($process->status, ['running', 'finished'], true)) {
            Log::info("Skipping job for Process {$this->processId}: already in status '{$process->status}'.");
            return;
        }

        $process->update(['status' => 'running']);

        try {
            app(DeliveryNoteProcessorService::class)
                ->setTargetPath(config('delivery_note_processor.target_folder'))
                ->run($process);

        } catch (Throwable $e) {
            $process->update([
                'status' => 'failed',
                'failed_message' => $e->getMessage(),
                'failed_trace' => $e->getTraceAsString(),
            ]);

            Log::error($e->getMessage() . ' - ' . $e->getTraceAsString());
        }
    }
}
