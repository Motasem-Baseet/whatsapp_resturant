<?php

namespace Tests\Feature\Inventory;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Restaurant;
use App\Services\Orders\CreateOrder;
use App\Services\Orders\UpdateOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 27: stock restoration on order cancellation, via
 * UpdateOrderStatus - the single existing lifecycle service (Phase 20),
 * now also Phase 27's authoritative place for this.
 */
class CancellationStockRestorationTest extends TestCase
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

    private function createStockTrackedOrder(Restaurant $restaurant, Product $product, int $quantity): Order
    {
        return app(CreateOrder::class)->handle($restaurant, $this->createCustomer($restaurant), [
            ['product_id' => $product->id, 'quantity' => $quantity],
        ]);
    }

    // --- Successful cancellation ---------------------------------------------

    public function test_successful_cancellation_restores_the_deducted_stock(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['stock_quantity' => 10]);
        $order = $this->createStockTrackedOrder($restaurant, $product, 4);
        $this->assertSame(6, $product->fresh()->stock_quantity);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled);

        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_cancellation_restores_stock_for_every_item_on_a_multi_product_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $productA = $this->createProduct($restaurant, ['stock_quantity' => 10]);
        $productB = $this->createProduct($restaurant, ['stock_quantity' => 20]);

        $order = app(CreateOrder::class)->handle($restaurant, $customer, [
            ['product_id' => $productA->id, 'quantity' => 3],
            ['product_id' => $productB->id, 'quantity' => 7],
        ]);
        $this->assertSame(7, $productA->fresh()->stock_quantity);
        $this->assertSame(13, $productB->fresh()->stock_quantity);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled);

        $this->assertSame(10, $productA->fresh()->stock_quantity);
        $this->assertSame(20, $productB->fresh()->stock_quantity);
    }

    public function test_cancelling_an_order_with_an_untracked_product_does_not_touch_its_null_stock(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['stock_quantity' => null]);
        $order = $this->createStockTrackedOrder($restaurant, $product, 5);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled);

        $this->assertNull($product->fresh()->stock_quantity);
    }

    // --- Invalid / repeated cancellation must not restore stock --------------

    public function test_an_invalid_cancellation_transition_does_not_restore_stock(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['stock_quantity' => 10]);
        $order = $this->createStockTrackedOrder($restaurant, $product, 4);

        // Progress to Ready, from which Cancelled is not an allowed
        // transition.
        $order = app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Confirmed);
        $order = app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Preparing);
        $order = app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Ready);
        $this->assertSame(6, $product->fresh()->stock_quantity);

        try {
            app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled);
            $this->fail('Expected an invalid transition exception.');
        } catch (\App\Exceptions\InvalidOrderStatusTransitionException) {
            // expected
        }

        $this->assertSame(6, $product->fresh()->stock_quantity);
    }

    public function test_repeated_cancellation_does_not_restore_stock_twice(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['stock_quantity' => 10]);
        $order = $this->createStockTrackedOrder($restaurant, $product, 4);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled);
        $this->assertSame(10, $product->fresh()->stock_quantity);

        try {
            app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled);
            $this->fail('Expected cancelling an already-cancelled order to fail.');
        } catch (\App\Exceptions\InvalidOrderStatusTransitionException) {
            // expected
        }

        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_completed_orders_do_not_restore_stock(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['stock_quantity' => 10]);
        $order = $this->createStockTrackedOrder($restaurant, $product, 4);

        $order = app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Confirmed);
        $order = app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Preparing);
        $order = app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Ready);
        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Completed);

        $this->assertSame(6, $product->fresh()->stock_quantity);
    }

    // --- Historical products later changed ------------------------------

    public function test_stock_is_restored_even_if_the_product_was_later_deactivated(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['stock_quantity' => 10]);
        $order = $this->createStockTrackedOrder($restaurant, $product, 4);

        $product->is_active = false;
        $product->save();

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled);

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertFalse($product->fresh()->is_active);
    }

    public function test_stock_is_restored_even_if_the_product_was_later_made_unavailable(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['stock_quantity' => 10]);
        $order = $this->createStockTrackedOrder($restaurant, $product, 4);

        $product->is_available = false;
        $product->save();

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled);

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertFalse($product->fresh()->is_available);
    }

    // --- Missing product handled safely ---------------------------------

    public function test_cancelling_an_order_whose_item_has_no_linked_product_does_not_error(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $order = Order::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id, 'status' => OrderStatus::Pending]);
        OrderItem::factory()->create([
            'restaurant_id' => $restaurant->id,
            'order_id' => $order->id,
            'product_id' => null,
            'stock_deducted' => true,
            'quantity' => 3,
        ]);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    // --- Cross-tenant safety ---------------------------------------------

    /**
     * The strongest possible proof that cancellation can never restore
     * a foreign restaurant's stock: the database's own composite
     * foreign key (order_items.(product_id, restaurant_id) ->
     * products.(id, restaurant_id)) makes it categorically impossible
     * to even create an order item that links restaurant A's order to
     * restaurant B's product - not merely something the application
     * code happens to avoid. restoreStock()'s own tenant-scoped lookup
     * (via $order->restaurant->products(), never a bare Product::find)
     * is defense in depth on top of a state this schema cannot produce.
     */
    public function test_the_database_rejects_an_order_item_linking_a_different_restaurants_product(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = $this->createCustomer($restaurantA);
        $productB = $this->createProduct($restaurantB, ['stock_quantity' => 50]);

        $order = Order::factory()->create(['restaurant_id' => $restaurantA->id, 'customer_id' => $customerA->id, 'status' => OrderStatus::Pending]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        try {
            OrderItem::factory()->create([
                'restaurant_id' => $restaurantA->id,
                'order_id' => $order->id,
                'product_id' => $productB->id,
                'stock_deducted' => true,
                'quantity' => 5,
            ]);
        } finally {
            $this->assertSame(50, $productB->fresh()->stock_quantity);
        }
    }

    // --- Backward compatibility: pre-Phase-27 orders -----------------------

    /**
     * Simulates an order created before stock tracking existed - its
     * order_items default to stock_deducted = false (the migration's
     * own backward-compatible default). Cancelling it today, even
     * though the product has since become stock-tracked, must not
     * restore stock that this specific order never actually took.
     */
    public function test_an_order_predating_stock_tracking_does_not_restore_stock_on_cancellation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = $this->createCustomer($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => 10]);
        $order = Order::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id, 'status' => OrderStatus::Pending]);
        OrderItem::factory()->create([
            'restaurant_id' => $restaurant->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'stock_deducted' => false,
            'quantity' => 4,
        ]);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled);

        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    // --- Historical integrity -------------------------------------------

    public function test_cancellation_does_not_alter_the_order_items_historical_snapshot(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['stock_quantity' => 10, 'price' => '9.99']);
        $order = $this->createStockTrackedOrder($restaurant, $product, 2);
        $item = $order->items->first();

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled);

        $freshItem = $item->fresh();
        $this->assertSame($item->product_name, $freshItem->product_name);
        $this->assertSame((string) $item->unit_price, (string) $freshItem->unit_price);
        $this->assertSame((string) $item->line_total, (string) $freshItem->line_total);
        $this->assertSame($item->quantity, $freshItem->quantity);
    }
}
