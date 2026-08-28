<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * restaurant_id is deliberately derived from the customer rather
     * than generated independently - the two must always agree (the
     * composite foreign key in the orders table enforces this), so a
     * naive `Restaurant::factory()` here would create a mismatched pair
     * and fail.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'restaurant_id' => fn (array $attributes) => Customer::find($attributes['customer_id'])->restaurant_id,
            'status' => OrderStatus::Pending,
            'subtotal' => '0.00',
            'total' => '0.00',
        ];
    }
}
