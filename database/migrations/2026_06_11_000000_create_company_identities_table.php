<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The canonical company. The FIRST trusted extraction for a real
     * company becomes its canonical_name; every later OCR/LLM variant is
     * matched against it and stored as an alias instead of spawning a new
     * filename. `rename_slug` is the filename-safe form actually used when
     * building delivery-note filenames.
     */
    public function up(): void
    {
        Schema::create('company_identities', function (Blueprint $table) {
            $table->id();
            $table->string('canonical_name');
            // Filename-safe slug used verbatim in generated filenames.
            $table->string('rename_slug')->index();
            // Match key: lowercased/ascii-folded/whitespace-collapsed form
            // used for LIKE candidate lookup and fuzzy comparison.
            $table->string('normalized_canonical')->index();
            $table->string('created_from_raw_name')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->decimal('confidence_at_creation', 5, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_identities');
    }
};
