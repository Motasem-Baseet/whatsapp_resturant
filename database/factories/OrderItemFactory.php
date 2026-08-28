<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * restaurant_id is deliberately derived from the order rather than
     * generated independently - the two must always agree (the
     * composite foreign key in the order_items table enforces this).
     * product_id defaults to null (a valid, nullable "snapshot only"
     * item); pass an explicit product_id and matching restaurant_id
     * together when a real product link is needed.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->randomFloat(2, 1, 50);
        $quantity = fake()->numberBetween(1, 5);

        return [
            'order_id' => Order::factory(),
            'restaurant_id' => fn (array $attributes) => Order::find($attributes['order_id'])->restaurant_id,
            'product_id' => null,
            'product_name' => ucfirst(fake()->words(2, true)),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => round($unitPrice * $quantity, 2),
        ];
    }
}
