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
        Schema::table('orders', function (Blueprint $table) {
            // Nullable and optional by design - an operational
            // cancellation (wrong order, customer no-show, out of
            // stock) shouldn't be blocked on typing an explanation.
            // Mirrors the existing `notes` column's type (text, no
            // length cap at the schema level - max:1000 is enforced at
            // the application/validation layer instead).
            $table->text('cancellation_reason')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cancellation_reason');
        });
    }
};
