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
        // Lets OrderItem carry a composite foreign key down to
        // (product_id, restaurant_id), so the database itself can never
        // store an order item whose restaurant differs from its
        // product's restaurant.
        Schema::table('products', function (Blueprint $table) {
            $table->unique(['id', 'restaurant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['id', 'restaurant_id']);
        });
    }
};
