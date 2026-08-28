<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusHistory>
 */
class OrderStatusHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * restaurant_id is deliberately derived from the order rather than
     * generated independently - the two must always agree (the
     * composite foreign key enforces this), matching OrderFactory's own
     * customer-derived restaurant_id pattern.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'restaurant_id' => fn (array $attributes) => Order::find($attributes['order_id'])->restaurant_id,
            'from_status' => OrderStatus::Pending->value,
            'to_status' => OrderStatus::Confirmed->value,
            'changed_by' => null,
        ];
    }
}
