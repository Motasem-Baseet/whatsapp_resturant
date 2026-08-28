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
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id');
            $table->string('from_status');
            $table->string('to_status');
            $table->foreignId('changed_by')->nullable();
            $table->timestamps();

            // Composite foreign keys, matching the orders table's own
            // pattern: each row must belong to the same restaurant as
            // both the order it references and the user who made the
            // change. changed_by is nullable (a transition made with no
            // acting user, e.g. from a console/test context) and exempt
            // from the check in that case, same as
            // orders.created_by/conversation_id.
            $table->foreign(['order_id', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])
                ->on('orders')
                ->restrictOnDelete();

            $table->foreign(['changed_by', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])
                ->on('users')
                ->restrictOnDelete();

            $table->index(['order_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
