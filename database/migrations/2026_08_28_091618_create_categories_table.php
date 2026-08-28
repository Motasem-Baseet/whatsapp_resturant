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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Lets products carry a composite foreign key down to
            // (category_id, restaurant_id), so the database itself can
            // never store a product whose restaurant differs from its
            // category's restaurant.
            $table->unique(['id', 'restaurant_id']);

            // A restaurant cannot have two categories with the same
            // name, but different restaurants may reuse names freely.
            $table->unique(['restaurant_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
