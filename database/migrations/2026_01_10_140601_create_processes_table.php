<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('processes', function (Blueprint $table) {
            $table->id();
            $table->string('source_file_path');
            $table->string('source_file_hash', 64);
            $table->unsignedBigInteger('source_file_size')->nullable();
            $table->timestamp('source_file_mtime')->nullable();
            $table->string('target_file_path');
            $table->string('status')->default('pending')->index();
            $table->string('company_name')->nullable();
            $table->decimal('company_name_certainty')->nullable();
            $table->string('delivery_note_id')->nullable();
            $table->decimal('delivery_note_certainty')->nullable();
            $table->string('invoice_id')->nullable();
            $table->decimal('invoice_id_certainty')->nullable();
            $table->text('ocr_result')->nullable();
            $table->string('locale')->nullable();
            $table->integer('input_token')->nullable();
            $table->integer('output_token')->nullable();
            $table->integer('total_token')->nullable();
            $table->string('failed_message')->nullable();
            $table->text('failed_trace')->nullable();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processes');
    }
};
