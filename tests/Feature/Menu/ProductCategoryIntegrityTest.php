<?php

namespace Tests\Feature\Menu;

use App\Models\Category;
use App\Models\Restaurant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the database-level safeguard for Task 3: a product's
 * restaurant_id and its category's restaurant_id must always agree.
 *
 * The products table's composite foreign key -
 * (category_id, restaurant_id) references categories(id, restaurant_id)
 * - is exercised directly here via raw inserts, bypassing Eloquent and
 * all application-level validation entirely, to confirm the database
 * itself (not just app code) refuses an inconsistent row.
 */
class ProductCategoryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_rejects_a_product_whose_restaurant_does_not_match_its_categorys_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $categoryA = Category::factory()->create(['restaurant_id' => $restaurantA->id]);

        $this->expectException(QueryException::class);

        // Deliberately mismatched: category belongs to A, but the raw
        // insert claims restaurant B.
        DB::table('products')->insert([
            'restaurant_id' => $restaurantB->id,
            'category_id' => $categoryA->id,
            'name' => 'Illegal Cross-Tenant Product',
            'price' => 9.99,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_allows_a_product_whose_restaurant_matches_its_categorys_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        DB::table('products')->insert([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Legitimate Product',
            'price' => 9.99,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('products', [
            'name' => 'Legitimate Product',
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
        ]);
    }
}
