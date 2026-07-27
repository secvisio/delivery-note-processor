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
use Illuminate\Support\Str;
use Throwable;

class ProcessDeliveryNoteJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Worst-case wall clock for one document, which this must stay above:
     *
     *   Tesseract                                     120s
     *   FrachtbriefAgent      (HTTP timeout)           30s
     *   ProductionOrderAgent  (HTTP timeout)           30s
     *   DeliveryNoteAgent     (HTTP timeout)           30s
     *                                                -----
     *                                                 210s
     *
     * Was 180s, which exactly matched the previous two-agent worst case and
     * left no headroom; the Frachtbrief agent added a third 30s call. Raised to
     * 240s (210s + margin for PDF conversion and disk IO) rather than shortening
     * the OCR budget, which would cost recognition quality on slow scans.
     *
     * The invariant to preserve is:
     *   agent timeout (30s) < job timeout (240s) < queue retry_after (600s redis)
     * and Horizon's supervisor timeout (config/horizon.php) must match this.
     */
    public int $timeout = 240;

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
                'failed_message' => Str::limit($e->getMessage(), 1000),
                'failed_trace' => $e->getTraceAsString(),
            ]);

            Log::error($e->getMessage() . ' - ' . $e->getTraceAsString());
        }
    }
}
