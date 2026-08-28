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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversation_id');
            $table->string('direction');
            $table->text('content')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            // Composite foreign key: a message's (conversation_id,
            // restaurant_id) pair must match an existing conversation
            // row with that exact id AND restaurant_id - the database
            // itself refuses a message attached to a conversation from
            // a different restaurant.
            $table->foreign(['conversation_id', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])
                ->on('conversations')
                ->restrictOnDelete();

            // provider_message_id idempotency: scoped to the owning
            // restaurant rather than treated as globally unique, since
            // this phase does not model a provider entity. This is the
            // boundary that actually matters for idempotency (a
            // restaurant's own WhatsApp number redelivering the same
            // webhook), without assuming a cross-provider ID-space
            // guarantee we have no way to verify yet. NULL values
            // (local/manual messages) are exempt - both MySQL and
            // SQLite treat NULLs as distinct from one another in a
            // unique index, so any number of null-provider messages can
            // coexist.
            $table->unique(['restaurant_id', 'provider_message_id']);

            $table->index(['conversation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
