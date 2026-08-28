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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id');
            $table->foreignId('product_id')->nullable();
            $table->string('product_name');
            $table->decimal('unit_price', 10, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 10, 2);
            $table->timestamps();

            // Composite foreign key: an order item's (order_id,
            // restaurant_id) pair must match an existing order row with
            // that exact id AND restaurant_id.
            $table->foreign(['order_id', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])
                ->on('orders')
                ->restrictOnDelete();

            // Composite foreign key: when product_id is set, it must
            // belong to the same restaurant as the order item. NULL is
            // exempt from this check (a future "product was removed"
            // state - not implemented in this phase, but the schema
            // supports it: product_name/unit_price already hold the
            // snapshot, so nulling product_id later loses no history).
            $table->foreign(['product_id', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])
                ->on('products')
                ->restrictOnDelete();

            $table->index('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
