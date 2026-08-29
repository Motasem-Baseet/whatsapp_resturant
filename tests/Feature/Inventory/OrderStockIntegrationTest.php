<?php

namespace Tests\Feature\Inventory;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Services\Orders\CreateOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 27: CreateOrder's stock validation, locking, and deduction.
 * Exercised directly against the service (matching OrderManagementTest's
 * own style) rather than through Livewire, since the business rule
 * under test lives entirely in CreateOrder itself.
 */
class OrderStockIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(Restaurant $restaurant, array $attributes = []): Product
    {
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);

        return Product::factory()->create(array_merge([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'is_active' => true,
        ], $attributes));
    }

    private function createCustomer(Restaurant $restaurant): Customer
    {
        return Customer::factory()->create(['restaurant_id' => $restaurant->id]);
    }

    // --- Stock deduction -----------------------------------------------------

    public function test_a_valid_order_reduces_stock_by_the_ordered_quantity(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => 10]);

        app(CreateOrder::class)->handle($restaurant, $customer, [
            ['product_id' => $product->id, 'quantity' => 3],
        ]);

        $this->assertSame(7, $product->fresh()->stock_quantity);
    }

    public function test_order_creation_leaves_untracked_stock_products_at_null(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => null]);

        app(CreateOrder::class)->handle($restaurant, $customer, [
            ['product_id' => $product->id, 'quantity' => 100],
        ]);

        $this->assertNull($product->fresh()->stock_quantity);
    }

    public function test_the_created_order_item_records_that_stock_was_deducted(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => 10]);

        $order = app(CreateOrder::class)->handle($restaurant, $customer, [
            ['product_id' => $product->id, 'quantity' => 2],
        ]);

        $this->assertTrue($order->items->first()->stock_deducted);
    }

    public function test_the_created_order_item_records_no_deduction_for_an_untracked_product(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => null]);

        $order = app(CreateOrder::class)->handle($restaurant, $customer, [
            ['product_id' => $product->id, 'quantity' => 2],
        ]);

        $this->assertFalse($order->items->first()->stock_deducted);
    }

    // --- Insufficient stock ----------------------------------------------

    public function test_insufficient_stock_rejects_the_entire_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => 3]);

        $this->expectException(InvalidArgumentException::class);

        try {
            app(CreateOrder::class)->handle($restaurant, $customer, [
                ['product_id' => $product->id, 'quantity' => 5],
            ]);
        } finally {
            $this->assertSame(3, $product->fresh()->stock_quantity);
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('order_items', 0);
        }
    }

    public function test_a_zero_stock_product_cannot_be_ordered_at_all(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => 0]);

        $this->expectException(InvalidArgumentException::class);

        try {
            app(CreateOrder::class)->handle($restaurant, $customer, [
                ['product_id' => $product->id, 'quantity' => 1],
            ]);
        } finally {
            $this->assertSame(0, $product->fresh()->stock_quantity);
        }
    }

    /**
     * A multi-product order where only one product has insufficient
     * stock must reject the whole order - the product that *did* have
     * enough stock must not have anything decremented either, since
     * CreateOrder validates every line before writing anything.
     */
    public function test_insufficient_stock_on_one_product_leaves_every_products_stock_unchanged(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $wellStocked = $this->createProduct($restaurant, ['stock_quantity' => 50]);
        $shortStocked = $this->createProduct($restaurant, ['stock_quantity' => 2]);

        try {
            app(CreateOrder::class)->handle($restaurant, $customer, [
                ['product_id' => $wellStocked->id, 'quantity' => 5],
                ['product_id' => $shortStocked->id, 'quantity' => 5],
            ]);
            $this->fail('Expected insufficient stock to reject the order.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(50, $wellStocked->fresh()->stock_quantity);
        $this->assertSame(2, $shortStocked->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
    }

    // --- Duplicate product merging ------------------------------------------

    public function test_duplicate_product_lines_are_merged_before_stock_is_validated(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        // 5 in stock; two lines of 3 each (8 total) must be rejected as
        // a single, merged request for 8 - never checked as two
        // separate, individually-satisfiable requests for 3.
        $product = $this->createProduct($restaurant, ['stock_quantity' => 5]);

        $this->expectException(InvalidArgumentException::class);

        try {
            app(CreateOrder::class)->handle($restaurant, $customer, [
                ['product_id' => $product->id, 'quantity' => 3],
                ['product_id' => $product->id, 'quantity' => 3],
            ]);
        } finally {
            $this->assertSame(5, $product->fresh()->stock_quantity);
        }
    }

    public function test_merged_duplicate_lines_that_exactly_match_available_stock_succeed(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => 5]);

        $order = app(CreateOrder::class)->handle($restaurant, $customer, [
            ['product_id' => $product->id, 'quantity' => 2],
            ['product_id' => $product->id, 'quantity' => 3],
        ]);

        $this->assertCount(1, $order->items);
        $this->assertSame(5, $order->items->first()->quantity);
        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    // --- Forged/invalid products ---------------------------------------------

    public function test_a_forged_unavailable_product_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $product = $this->createProduct($restaurant, ['is_available' => false]);

        $this->expectException(InvalidArgumentException::class);

        app(CreateOrder::class)->handle($restaurant, $customer, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]);
    }

    public function test_a_forged_inactive_product_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $product = $this->createProduct($restaurant, ['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);

        app(CreateOrder::class)->handle($restaurant, $customer, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]);
    }

    public function test_a_cross_tenant_product_is_rejected(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = $this->createCustomer($restaurantA);
        $productB = $this->createProduct($restaurantB, ['stock_quantity' => 10]);

        $this->expectException(InvalidArgumentException::class);

        try {
            app(CreateOrder::class)->handle($restaurantA, $customerA, [
                ['product_id' => $productB->id, 'quantity' => 1],
            ]);
        } finally {
            $this->assertSame(10, $productB->fresh()->stock_quantity);
        }
    }

    // --- Concurrency (as far as the test environment allows) ----------------

    /**
     * PHPUnit is single-threaded and the test suite runs against a
     * single in-memory SQLite connection, so true parallel requests
     * cannot be simulated here. What this proves instead: the
     * lockForUpdate() + check-then-decrement sequence in
     * CreateOrder::resolveOrderableProducts() correctly enforces the
     * stock ceiling across successive orders for the same product - a
     * second order is rejected as soon as the first has consumed the
     * available stock, exactly as it would if the two had raced.
     */
    public function test_sequential_orders_cannot_oversell_a_products_stock(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => 5]);

        app(CreateOrder::class)->handle($restaurant, $customer, [
            ['product_id' => $product->id, 'quantity' => 3],
        ]);
        $this->assertSame(2, $product->fresh()->stock_quantity);

        try {
            app(CreateOrder::class)->handle($restaurant, $customer, [
                ['product_id' => $product->id, 'quantity' => 3],
            ]);
            $this->fail('Expected the second order to be rejected for insufficient stock.');
        } catch (InvalidArgumentException) {
            // expected
        }

        // Stock never dropped below zero and reflects exactly one
        // successful deduction.
        $this->assertSame(2, $product->fresh()->stock_quantity);
    }

    public function test_generating_the_order_still_uses_a_bounded_number_of_queries_with_stock_tracking(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $products = collect(range(1, 5))->map(fn () => $this->createProduct($restaurant, ['stock_quantity' => 10]));

        \Illuminate\Support\Facades\DB::enableQueryLog();
        app(CreateOrder::class)->handle($restaurant, $customer, $products->map(fn ($p) => ['product_id' => $p->id, 'quantity' => 1])->all());
        $queryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // One locked SELECT for all products, one order insert, one
        // insert + one decrement per item, one final order update - not
        // one query per product beyond that fixed shape.
        $this->assertLessThanOrEqual(20, $queryCount);
    }
}
