<?php

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderIntegrityTest extends TestCase
{
    use RefreshDatabase;

    // --- Relationships ------------------------------------------------

    public function test_a_restaurant_can_have_many_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $order = Order::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->assertTrue($restaurant->orders->contains($order));
    }

    public function test_a_restaurant_can_have_many_order_items(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $order = Order::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $item = OrderItem::factory()->create(['restaurant_id' => $restaurant->id, 'order_id' => $order->id]);

        $this->assertTrue($restaurant->orderItems->contains($item));
    }

    public function test_a_customer_can_have_many_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $orderA = Order::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $orderB = Order::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->assertCount(2, $customer->orders);
        $this->assertTrue($customer->orders->contains($orderA));
        $this->assertTrue($customer->orders->contains($orderB));
    }

    public function test_a_conversation_can_have_many_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $order = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
        ]);

        $this->assertTrue($conversation->orders->contains($order));
    }

    public function test_an_order_can_have_many_order_items(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $order = Order::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $itemA = OrderItem::factory()->create(['restaurant_id' => $restaurant->id, 'order_id' => $order->id]);
        $itemB = OrderItem::factory()->create(['restaurant_id' => $restaurant->id, 'order_id' => $order->id]);

        $this->assertCount(2, $order->items);
        $this->assertTrue($order->items->contains($itemA));
        $this->assertTrue($order->items->contains($itemB));
    }

    public function test_order_defaults_to_pending(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $order = new Order();
        $order->restaurant_id = $restaurant->id;
        $order->customer_id = $customer->id;
        $order->subtotal = '0.00';
        $order->total = '0.00';
        $order->save();

        $this->assertSame(\App\Enums\OrderStatus::Pending, $order->status);
    }

    // --- Database integrity (raw inserts, bypassing Eloquent) -----------

    public function test_the_database_rejects_an_order_whose_restaurant_does_not_match_its_customers_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);

        $this->expectException(QueryException::class);

        DB::table('orders')->insert([
            'restaurant_id' => $restaurantA->id,
            'customer_id' => $customerB->id,
            'status' => 'pending',
            'subtotal' => 0,
            'total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_rejects_an_order_whose_restaurant_does_not_match_its_conversations_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);
        $conversationB = Conversation::factory()->create(['restaurant_id' => $restaurantB->id, 'customer_id' => $customerB->id]);

        $this->expectException(QueryException::class);

        DB::table('orders')->insert([
            'restaurant_id' => $restaurantA->id,
            'customer_id' => $customerA->id,
            'conversation_id' => $conversationB->id,
            'status' => 'pending',
            'subtotal' => 0,
            'total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_rejects_an_order_whose_created_by_belongs_to_another_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $userB = User::factory()->create(['restaurant_id' => $restaurantB->id]);

        $this->expectException(QueryException::class);

        DB::table('orders')->insert([
            'restaurant_id' => $restaurantA->id,
            'customer_id' => $customerA->id,
            'created_by' => $userB->id,
            'status' => 'pending',
            'subtotal' => 0,
            'total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_rejects_an_order_item_whose_restaurant_does_not_match_its_orders_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $orderA = Order::factory()->create(['restaurant_id' => $restaurantA->id, 'customer_id' => $customerA->id]);

        $this->expectException(QueryException::class);

        DB::table('order_items')->insert([
            'restaurant_id' => $restaurantB->id,
            'order_id' => $orderA->id,
            'product_name' => 'Illegal Item',
            'unit_price' => 5,
            'quantity' => 1,
            'line_total' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_rejects_an_order_item_whose_product_belongs_to_another_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $orderA = Order::factory()->create(['restaurant_id' => $restaurantA->id, 'customer_id' => $customerA->id]);
        $categoryB = Category::factory()->create(['restaurant_id' => $restaurantB->id]);
        $productB = Product::factory()->create(['restaurant_id' => $restaurantB->id, 'category_id' => $categoryB->id]);

        $this->expectException(QueryException::class);

        DB::table('order_items')->insert([
            'restaurant_id' => $restaurantA->id,
            'order_id' => $orderA->id,
            'product_id' => $productB->id,
            'product_name' => 'Illegal Item',
            'unit_price' => 5,
            'quantity' => 1,
            'line_total' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_allows_matching_restaurant_relationships_throughout(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'category_id' => $category->id]);

        DB::table('orders')->insert([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'created_by' => $user->id,
            'status' => 'pending',
            'subtotal' => 5,
            'total' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = Order::query()->latest('id')->first();

        DB::table('order_items')->insert([
            'restaurant_id' => $restaurant->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => $product->price,
            'quantity' => 1,
            'line_total' => $product->price,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'restaurant_id' => $restaurant->id]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_id' => $product->id]);
    }
}
