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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id');
            $table->foreignId('assigned_user_id')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            // Composite foreign key: a conversation's (customer_id,
            // restaurant_id) pair must match an existing customer row
            // with that exact id AND restaurant_id - the database
            // itself refuses a conversation attached to a customer from
            // a different restaurant. Same pattern established in
            // Phase 4 for products/categories.
            $table->foreign(['customer_id', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])
                ->on('customers')
                ->restrictOnDelete();

            // Composite foreign key: when assigned_user_id is set, it
            // must belong to the same restaurant as the conversation.
            // A NULL assigned_user_id (unassigned) is exempt from this
            // check by standard SQL foreign key semantics.
            $table->foreign(['assigned_user_id', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])
                ->on('users')
                ->restrictOnDelete();

            // Lets Message carry a composite foreign key down to
            // (conversation_id, restaurant_id).
            $table->unique(['id', 'restaurant_id']);

            $table->index(['restaurant_id', 'last_message_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
