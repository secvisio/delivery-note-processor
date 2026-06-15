<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Every raw company name ever observed for a canonical identity. One row
     * per distinct normalized variant; `times_seen` counts repeats. This is
     * what lets `company-ux-128` collapse onto the canonical `company-ux-123`
     * on a later scan instead of producing a fresh filename.
     */
    public function up(): void
    {
        Schema::create('company_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_identity_id')
                ->constrained('company_identities')
                ->cascadeOnDelete();
            $table->string('raw_name');
            $table->string('normalized_name')->index();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('times_seen')->default(1);
            $table->decimal('match_confidence', 5, 4)->nullable();
            $table->string('source')->nullable();
            $table->timestamps();

            // One alias row per (identity, normalized variant).
            $table->unique(['company_identity_id', 'normalized_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_aliases');
    }
};
