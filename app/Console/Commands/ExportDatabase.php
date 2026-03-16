<?php

namespace App\Console\Commands;

use App\Models\Process;
use Illuminate\Console\Command;

class ExportDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:export-db';

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

//        $processes = Process::all();

        $file = storage_path('processes.csv');
        $handle = fopen($file, 'w');

// header
        fputcsv($handle, [
            'id',
            'source_file_path',
            'source_file_hash',
            'source_file_size',
            'source_file_mtime',
            'target_file_path',
            'status',
            'company_name',
            'company_name_certainty',
            'delivery_note_id',
            'delivery_note_certainty',
            'invoice_id',
            'invoice_id_certainty',
            'failed_message',
            'created_at'
        ], ';', '"', '');

        Process::chunk(1000, function ($users) use ($handle) {
            foreach ($users as $user) {
                fputcsv($handle, [
                    $user->id,
                    $user->source_file_path,
                    $user->source_file_size,
                    $user->source_file_mtime,
                    $user->target_file_path,
                    $user->status,
                    $user->company_name,
                    $user->company_name_certainty,
                    $user->delivery_note_id,
                    $user->delivery_note_certainty,
                    $user->invoice_id,
                    $user->invoice_id_certainty,
                    $user->failed_message,
                    $user->created_at,
                ], ';', '"', '');
            }
        });

        fclose($handle);

    }
}
