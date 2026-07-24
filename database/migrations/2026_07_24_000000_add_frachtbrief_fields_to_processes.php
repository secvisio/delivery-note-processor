<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Frachtbrief extraction results to the processes table.
 *
 * All columns are nullable and nothing is backfilled: existing rows keep
 * `document_type = null`, which the dashboard treats as "legacy / delivery
 * note" and renders exactly as before. The delivery-note and production-order
 * branches are untouched and continue to leave `document_type` null.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('processes', function (Blueprint $table) {
            $table->string('document_type')->nullable()->index()->after('status');

            $table->string('frachtbrief_order_number')->nullable();
            $table->decimal('frachtbrief_order_number_percent')->nullable();

            // Stored as a plain string (not a date column) so the normalized
            // 'YYYY-MM-DD' segment that ends up in the filename is exactly what
            // is persisted, with no driver-dependent casting in between.
            $table->string('frachtbrief_pickup_date', 10)->nullable();
            $table->decimal('frachtbrief_pickup_date_percent')->nullable();

            $table->string('frachtbrief_recipient_company')->nullable();
            $table->decimal('frachtbrief_recipient_company_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('processes', function (Blueprint $table) {
            $table->dropIndex(['document_type']);

            $table->dropColumn([
                'document_type',
                'frachtbrief_order_number',
                'frachtbrief_order_number_percent',
                'frachtbrief_pickup_date',
                'frachtbrief_pickup_date_percent',
                'frachtbrief_recipient_company',
                'frachtbrief_recipient_company_percent',
            ]);
        });
    }
};
