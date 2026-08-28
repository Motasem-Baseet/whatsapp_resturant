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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('phone', 30);
            $table->text('notes')->nullable();
            $table->timestamps();

            // A phone number must be unique within a restaurant, but the
            // same phone may belong to a different customer at a
            // different restaurant.
            $table->unique(['restaurant_id', 'phone']);

            $table->index(['restaurant_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
