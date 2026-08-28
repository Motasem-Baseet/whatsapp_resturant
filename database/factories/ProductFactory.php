<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * restaurant_id is deliberately derived from the category rather
     * than generated independently - the two must always agree (the
     * composite foreign key in the products table enforces this), so a
     * naive `Restaurant::factory()` here would create a mismatched pair
     * and fail.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'restaurant_id' => fn (array $attributes) => Category::find($attributes['category_id'])->restaurant_id,
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomFloat(2, 1, 100),
            'is_active' => true,
        ];
    }
}
