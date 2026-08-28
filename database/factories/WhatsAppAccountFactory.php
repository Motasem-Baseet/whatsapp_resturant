<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\WhatsAppAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsAppAccount>
 */
class WhatsAppAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * access_token is set directly here even though it is not fillable
     * on the model - factories operate in an unguarded context (the
     * same established pattern relied on throughout this project for
     * restaurant_id and similar trusted-only fields).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'phone_number_id' => fake()->unique()->numerify('##############'),
            'business_account_id' => fake()->numerify('##############'),
            'display_phone_number' => fake()->numerify('+1##########'),
            'access_token' => Str::random(64),
            'verify_token' => Str::random(32),
            'app_secret' => null,
            'is_active' => true,
        ];
    }

    /**
     * An account with no app_secret configured, so incoming webhook
     * signature verification is skipped for it.
     */
    public function withoutAppSecret(): static
    {
        return $this->state(['app_secret' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
