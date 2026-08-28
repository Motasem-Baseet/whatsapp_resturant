<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\Orders\UpdateOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function createOrder(OrderStatus $status): Order
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        return Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, array{0: OrderStatus, 1: OrderStatus}>
     */
    public static function validTransitions(): array
    {
        return [
            'pending -> confirmed' => [OrderStatus::Pending, OrderStatus::Confirmed],
            'pending -> cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled],
            'confirmed -> preparing' => [OrderStatus::Confirmed, OrderStatus::Preparing],
            'confirmed -> cancelled' => [OrderStatus::Confirmed, OrderStatus::Cancelled],
            'preparing -> ready' => [OrderStatus::Preparing, OrderStatus::Ready],
            'preparing -> cancelled' => [OrderStatus::Preparing, OrderStatus::Cancelled],
            'ready -> completed' => [OrderStatus::Ready, OrderStatus::Completed],
        ];
    }

    #[DataProvider('validTransitions')]
    public function test_valid_transitions_are_allowed(OrderStatus $from, OrderStatus $to): void
    {
        $order = $this->createOrder($from);

        app(UpdateOrderStatus::class)->handle($order, $to);

        $this->assertSame($to, $order->fresh()->status);
    }

    /**
     * @return array<string, array{0: OrderStatus, 1: OrderStatus}>
     */
    public static function invalidTransitions(): array
    {
        return [
            'pending -> preparing' => [OrderStatus::Pending, OrderStatus::Preparing],
            'pending -> ready' => [OrderStatus::Pending, OrderStatus::Ready],
            'pending -> completed' => [OrderStatus::Pending, OrderStatus::Completed],
            'confirmed -> ready' => [OrderStatus::Confirmed, OrderStatus::Ready],
            'confirmed -> completed' => [OrderStatus::Confirmed, OrderStatus::Completed],
            'preparing -> completed' => [OrderStatus::Preparing, OrderStatus::Completed],
            'preparing -> confirmed' => [OrderStatus::Preparing, OrderStatus::Confirmed],
            'ready -> preparing' => [OrderStatus::Ready, OrderStatus::Preparing],
            'ready -> cancelled' => [OrderStatus::Ready, OrderStatus::Cancelled],
            'completed -> pending' => [OrderStatus::Completed, OrderStatus::Pending],
            'completed -> cancelled' => [OrderStatus::Completed, OrderStatus::Cancelled],
            'cancelled -> confirmed' => [OrderStatus::Cancelled, OrderStatus::Confirmed],
            'cancelled -> pending' => [OrderStatus::Cancelled, OrderStatus::Pending],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transitions_are_rejected(OrderStatus $from, OrderStatus $to): void
    {
        $order = $this->createOrder($from);

        $this->expectException(InvalidOrderStatusTransitionException::class);

        try {
            app(UpdateOrderStatus::class)->handle($order, $to);
        } finally {
            $this->assertSame($from, $order->fresh()->status);
        }
    }
}
