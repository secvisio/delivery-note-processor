<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Widen failed_message from VARCHAR(255) to TEXT so long exception
     * messages no longer overflow the column and crash the failure update.
     * The value is still truncated in code, but TEXT removes the hard cap.
     */
    public function up(): void
    {
        Schema::table('processes', function (Blueprint $table) {
            $table->text('failed_message')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('processes', function (Blueprint $table) {
            $table->string('failed_message')->nullable()->change();
        });
    }
};
