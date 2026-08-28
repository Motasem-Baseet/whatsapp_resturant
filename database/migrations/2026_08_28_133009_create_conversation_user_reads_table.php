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
        Schema::create('conversation_user_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversation_id');
            $table->foreignId('user_id');
            $table->timestamp('last_read_at');
            $table->timestamps();

            // Same composite-foreign-key pattern used everywhere else in
            // this schema (customers, conversations, messages): the
            // database itself refuses a read-state row whose
            // conversation_id/user_id belongs to a different restaurant
            // than the one stored on this row. This is stronger than
            // relying on Eloquent relationships alone - a raw insert
            // bypassing the application layer entirely still cannot
            // create cross-tenant read state.
            $table->foreign(['conversation_id', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])
                ->on('conversations')
                ->restrictOnDelete();

            $table->foreign(['user_id', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])
                ->on('users')
                ->restrictOnDelete();

            // One read-state row per user per conversation - the
            // authoritative idempotency/uniqueness guarantee for "mark
            // as read", enforced at the database level rather than only
            // assumed by application logic.
            $table->unique(['conversation_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_user_reads');
    }
};
