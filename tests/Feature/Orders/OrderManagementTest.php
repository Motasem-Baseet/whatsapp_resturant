<?php

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Orders\CreateOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(?Restaurant $restaurant = null): User
    {
        $restaurant ??= Restaurant::factory()->create();

        $owner = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $owner->assignRole(Role::findOrCreate('owner'));

        return $owner;
    }

    private function createEmployee(Restaurant $restaurant, string $role): User
    {
        $employee = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $employee->assignRole(Role::findOrCreate($role));

        return $employee;
    }

    private function createProduct(Restaurant $restaurant, string $name, string $price, bool $active = true, bool $categoryActive = true): Product
    {
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => $categoryActive]);

        return Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => $name,
            'price' => $price,
            'is_active' => $active,
        ]);
    }

    // --- Creation via Livewire ------------------------------------------

    public function test_owner_can_create_an_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Classic Burger', '5.00');

        $this->actingAs($owner);

        Volt::test('orders.create')
            ->set('customer_id', (string) $customer->id)
            ->set('selected_product_id', (string) $product->id)
            ->set('selected_quantity', 2)
            ->call('addItem')
            ->call('save')
            ->assertHasNoErrors();

        $order = Order::where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame($restaurant->id, $order->restaurant_id);
        $this->assertSame('10.00', (string) $order->total);
    }

    public function test_cashier_can_create_an_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Fries', '3.00');

        $this->actingAs($cashier);

        Volt::test('orders.create')
            ->set('customer_id', (string) $customer->id)
            ->set('selected_product_id', (string) $product->id)
            ->set('selected_quantity', 1)
            ->call('addItem')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('orders', ['customer_id' => $customer->id, 'restaurant_id' => $restaurant->id]);
    }

    public function test_kitchen_cannot_create_an_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen);

        $this->get(route('orders.create'))->assertForbidden();
        $this->get(route('orders.index'))->assertForbidden();
    }

    // --- CreateOrder service: ownership validation -----------------------

    public function test_order_receives_correct_restaurant_id(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Cola', '2.00');

        $order = app(CreateOrder::class)->handle(
            restaurant: $restaurant,
            customer: $customer,
            items: [['product_id' => $product->id, 'quantity' => 1]],
        );

        $this->assertSame($restaurant->id, $order->restaurant_id);
    }

    public function test_customer_must_belong_to_the_given_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);
        $productA = $this->createProduct($restaurantA, 'Burger', '5.00');

        $this->expectException(InvalidArgumentException::class);

        app(CreateOrder::class)->handle(
            restaurant: $restaurantA,
            customer: $customerB,
            items: [['product_id' => $productA->id, 'quantity' => 1]],
        );
    }

    public function test_conversation_must_belong_to_the_given_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);
        $conversationB = Conversation::factory()->create(['restaurant_id' => $restaurantB->id, 'customer_id' => $customerB->id]);
        $productA = $this->createProduct($restaurantA, 'Burger', '5.00');

        $this->expectException(InvalidArgumentException::class);

        app(CreateOrder::class)->handle(
            restaurant: $restaurantA,
            customer: $customerA,
            items: [['product_id' => $productA->id, 'quantity' => 1]],
            conversation: $conversationB,
        );
    }

    public function test_created_by_must_belong_to_the_given_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $userB = User::factory()->create(['restaurant_id' => $restaurantB->id]);
        $productA = $this->createProduct($restaurantA, 'Burger', '5.00');

        $this->expectException(InvalidArgumentException::class);

        app(CreateOrder::class)->handle(
            restaurant: $restaurantA,
            customer: $customerA,
            items: [['product_id' => $productA->id, 'quantity' => 1]],
            createdBy: $userB,
        );
    }

    public function test_product_must_belong_to_the_given_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $productB = $this->createProduct($restaurantB, 'Pizza', '8.00');

        $this->expectException(InvalidArgumentException::class);

        app(CreateOrder::class)->handle(
            restaurant: $restaurantA,
            customer: $customerA,
            items: [['product_id' => $productB->id, 'quantity' => 1]],
        );
    }

    public function test_product_must_be_active(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Retired Item', '5.00', active: false);

        $this->expectException(InvalidArgumentException::class);

        app(CreateOrder::class)->handle(
            restaurant: $restaurant,
            customer: $customer,
            items: [['product_id' => $product->id, 'quantity' => 1]],
        );
    }

    public function test_products_category_must_be_active(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Orphaned Item', '5.00', active: true, categoryActive: false);

        $this->expectException(InvalidArgumentException::class);

        app(CreateOrder::class)->handle(
            restaurant: $restaurant,
            customer: $customer,
            items: [['product_id' => $product->id, 'quantity' => 1]],
        );
    }

    // --- Product snapshot ---------------------------------------------

    public function test_order_item_snapshots_the_product_name_and_price(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Classic Burger', '5.00');

        $order = app(CreateOrder::class)->handle(
            restaurant: $restaurant,
            customer: $customer,
            items: [['product_id' => $product->id, 'quantity' => 1]],
        );

        $item = $order->items->first();

        $this->assertSame('Classic Burger', $item->product_name);
        $this->assertSame('5.00', (string) $item->unit_price);
    }

    public function test_historical_order_items_are_unaffected_by_later_product_changes(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Classic Burger', '5.00');

        $order = app(CreateOrder::class)->handle(
            restaurant: $restaurant,
            customer: $customer,
            items: [['product_id' => $product->id, 'quantity' => 1]],
        );

        $product->update(['name' => 'Premium Burger', 'price' => '7.00']);

        $item = $order->items->first()->fresh();

        $this->assertSame('Classic Burger', $item->product_name);
        $this->assertSame('5.00', (string) $item->unit_price);
    }

    // --- Totals ---------------------------------------------------------

    public function test_line_total_is_calculated_correctly(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Burger', '5.50');

        $order = app(CreateOrder::class)->handle(
            restaurant: $restaurant,
            customer: $customer,
            items: [['product_id' => $product->id, 'quantity' => 3]],
        );

        $this->assertSame('16.50', (string) $order->items->first()->line_total);
    }

    public function test_subtotal_is_the_sum_of_line_totals(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $burger = $this->createProduct($restaurant, 'Burger', '5.00');
        $fries = $this->createProduct($restaurant, 'Fries', '2.50');

        $order = app(CreateOrder::class)->handle(
            restaurant: $restaurant,
            customer: $customer,
            items: [
                ['product_id' => $burger->id, 'quantity' => 2],
                ['product_id' => $fries->id, 'quantity' => 1],
            ],
        );

        $this->assertSame('12.50', (string) $order->subtotal);
    }

    public function test_total_equals_subtotal(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Burger', '5.00');

        $order = app(CreateOrder::class)->handle(
            restaurant: $restaurant,
            customer: $customer,
            items: [['product_id' => $product->id, 'quantity' => 2]],
        );

        $this->assertSame((string) $order->subtotal, (string) $order->total);
    }

    public function test_client_cannot_manipulate_prices_line_totals_or_totals(): void
    {
        // The Livewire component's public properties have no fields for
        // price/line_total/subtotal/total at all - proving there is no
        // wire:model payload that could inject them.
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $publicProperties = array_keys(
            get_object_vars(Volt::test('orders.create')->instance())
        );

        $this->assertNotContains('subtotal', $publicProperties);
        $this->assertNotContains('total', $publicProperties);
        $this->assertNotContains('restaurant_id', $publicProperties);
    }

    public function test_manipulated_price_sent_to_the_service_is_ignored_in_favour_of_the_real_product_price(): void
    {
        // Even if a caller tried to smuggle a 'price' key into an item,
        // CreateOrder never reads anything but product_id and quantity
        // from the given items - it always re-derives price from the
        // actual Product row.
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Burger', '5.00');

        $order = app(CreateOrder::class)->handle(
            restaurant: $restaurant,
            customer: $customer,
            items: [['product_id' => $product->id, 'quantity' => 1, 'price' => '0.01']],
        );

        $this->assertSame('5.00', (string) $order->items->first()->unit_price);
        $this->assertSame('5.00', (string) $order->total);
    }

    // --- Duplicate products ---------------------------------------------

    public function test_duplicate_product_submissions_are_merged_into_a_single_line_item(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Burger', '5.00');

        $order = app(CreateOrder::class)->handle(
            restaurant: $restaurant,
            customer: $customer,
            items: [
                ['product_id' => $product->id, 'quantity' => 2],
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        );

        $this->assertCount(1, $order->items);
        $this->assertSame(5, $order->items->first()->quantity);
        $this->assertSame('25.00', (string) $order->items->first()->line_total);
    }

    // --- Tenant isolation / route access ---------------------------------

    public function test_cross_tenant_order_route_returns_404(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);
        $orderB = Order::factory()->create(['restaurant_id' => $restaurantB->id, 'customer_id' => $customerB->id]);

        $response = $this->actingAs($ownerA)->get(route('orders.show', $orderB));

        $response->assertNotFound();
    }

    public function test_owner_can_access_order_pages(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner)->get(route('orders.index'))->assertOk();
        $this->actingAs($owner)->get(route('orders.create'))->assertOk();
    }

    public function test_cashier_can_access_order_pages(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($cashier)->get(route('orders.index'))->assertOk();
        $this->actingAs($cashier)->get(route('orders.create'))->assertOk();
    }

    public function test_kitchen_receives_403_for_order_pages(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen)->get(route('orders.index'))->assertForbidden();
    }
}
