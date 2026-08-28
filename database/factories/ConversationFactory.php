<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * restaurant_id is deliberately derived from the customer rather
     * than generated independently - the two must always agree (the
     * composite foreign key in the conversations table enforces this),
     * so a naive `Restaurant::factory()` here would create a mismatched
     * pair and fail.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'restaurant_id' => fn (array $attributes) => Customer::find($attributes['customer_id'])->restaurant_id,
            'status' => ConversationStatus::Open,
        ];
    }
}
