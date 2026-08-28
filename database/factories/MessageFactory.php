<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * restaurant_id is deliberately derived from the conversation rather
     * than generated independently - the two must always agree (the
     * composite foreign key in the messages table enforces this), so a
     * naive `Restaurant::factory()` here would create a mismatched pair
     * and fail.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'restaurant_id' => fn (array $attributes) => Conversation::find($attributes['conversation_id'])->restaurant_id,
            'direction' => fake()->randomElement(MessageDirection::cases()),
            'content' => fake()->sentence(),
        ];
    }
}
