<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Process extends Model
{

    protected $fillable = [
        'source_file_path',
        'source_file_hash',
        'source_file_size',
        'source_file_mtime',
        'target_file_path',
        'status',
        'document_type',
        'frachtbrief_order_number',
        'frachtbrief_order_number_percent',
        'frachtbrief_pickup_date',
        'frachtbrief_pickup_date_percent',
        'frachtbrief_recipient_company',
        'frachtbrief_recipient_company_percent',
        'company_name',
        'company_name_certainty',
        'delivery_note_id',
        'delivery_note_certainty',
        'invoice_id',
        'invoice_id_certainty',
        'ocr_result',
        'locale',
        'input_token',
        'output_token',
        'total_token',
        'failed_message',
        'failed_trace',
    ];

    /**
     * Automatically encode ocr_result when saving
     */
    public function setOcrResultAttribute(?string $value): void
    {
        $this->attributes['ocr_result'] = $value !== null
            ? base64_encode($value)
            : null;
    }

    /**
     * Automatically decode ocr_result when retrieving
     */
    public function getOcrResultAttribute(?string $value): ?string
    {
        return $value !== null
            ? base64_decode($value)
            : null;
    }
}
