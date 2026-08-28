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
        // Lets Conversation carry a composite foreign key down to
        // (customer_id, restaurant_id), so the database itself can
        // never store a conversation whose restaurant differs from its
        // customer's restaurant.
        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['id', 'restaurant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['id', 'restaurant_id']);
        });
    }
};
