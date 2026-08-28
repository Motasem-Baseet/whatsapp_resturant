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
        // Provider-neutral delivery status (pending/sent/delivered/read/
        // failed) - deliberately a separate column from `direction`,
        // not overloaded onto it. Nullable: inbound messages (things we
        // received) have no delivery status of their own, so this only
        // gets populated for outbound messages.
        Schema::table('messages', function (Blueprint $table) {
            $table->string('status')->nullable()->after('direction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
