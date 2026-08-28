<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationUserRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationUserRead>
 */
class ConversationUserReadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * restaurant_id is deliberately derived from the conversation, not
     * generated independently - it must always agree with both
     * conversation_id and user_id's own restaurant (composite foreign
     * keys enforce this), so callers are expected to override
     * conversation_id/user_id together with a matching restaurant.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'restaurant_id' => fn (array $attributes) => Conversation::find($attributes['conversation_id'])->restaurant_id,
            'user_id' => User::factory(),
            'last_read_at' => now(),
        ];
    }
}
