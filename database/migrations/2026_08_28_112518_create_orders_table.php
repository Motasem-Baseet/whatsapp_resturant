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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id');
            $table->foreignId('conversation_id')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('total', 10, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Composite foreign keys: each of these must belong to the
            // same restaurant as the order itself. A NULL in the
            // nullable columns (conversation_id, created_by) exempts
            // that row from the corresponding check by standard SQL
            // foreign key semantics - the same pattern used for
            // Conversation.assigned_user_id in Phase 6.
            $table->foreign(['customer_id', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])
                ->on('customers')
                ->restrictOnDelete();

            $table->foreign(['conversation_id', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])
                ->on('conversations')
                ->restrictOnDelete();

            $table->foreign(['created_by', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])
                ->on('users')
                ->restrictOnDelete();

            // Lets OrderItem carry a composite foreign key down to
            // (order_id, restaurant_id).
            $table->unique(['id', 'restaurant_id']);

            $table->index(['restaurant_id', 'status']);
            $table->index(['restaurant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
