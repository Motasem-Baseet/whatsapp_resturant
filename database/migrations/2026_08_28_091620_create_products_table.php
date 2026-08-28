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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Composite foreign key: a product's (category_id,
            // restaurant_id) pair must match an existing category row
            // with that exact id AND restaurant_id. This makes it
            // impossible - at the database level, not just in
            // application code - for a product to reference a category
            // belonging to a different restaurant than the product
            // itself.
            $table->foreign(['category_id', 'restaurant_id'])
                ->references(['id', 'restaurant_id'])
                ->on('categories')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
