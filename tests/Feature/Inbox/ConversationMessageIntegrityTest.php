<?php

namespace Tests\Feature\Inbox;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Restaurant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConversationMessageIntegrityTest extends TestCase
{
    use RefreshDatabase;

    // --- Models and relationships ---------------------------------------

    public function test_a_restaurant_can_have_many_conversations(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->assertTrue($restaurant->conversations->contains($conversation));
    }

    public function test_a_restaurant_can_have_many_messages(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $message = Message::factory()->create(['restaurant_id' => $restaurant->id, 'conversation_id' => $conversation->id]);

        $this->assertTrue($restaurant->messages->contains($message));
    }

    public function test_a_customer_can_have_many_conversations(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversationA = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $conversationB = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->assertCount(2, $customer->conversations);
        $this->assertTrue($customer->conversations->contains($conversationA));
        $this->assertTrue($customer->conversations->contains($conversationB));
    }

    public function test_a_conversation_belongs_to_a_customer(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->assertTrue($conversation->customer->is($customer));
    }

    public function test_a_conversation_belongs_to_a_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->assertTrue($conversation->restaurant->is($restaurant));
    }

    public function test_a_message_belongs_to_a_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $message = Message::factory()->create(['restaurant_id' => $restaurant->id, 'conversation_id' => $conversation->id]);

        $this->assertTrue($message->conversation->is($conversation));
    }

    public function test_a_message_belongs_to_a_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $message = Message::factory()->create(['restaurant_id' => $restaurant->id, 'conversation_id' => $conversation->id]);

        $this->assertTrue($message->restaurant->is($restaurant));
    }

    public function test_conversation_defaults_to_open(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $conversation = new Conversation();
        $conversation->restaurant_id = $restaurant->id;
        $conversation->customer_id = $customer->id;
        $conversation->save();

        $this->assertSame(ConversationStatus::Open, $conversation->status);
    }

    public function test_conversation_status_enum_casts_correctly(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'status' => ConversationStatus::Closed,
        ]);

        $this->assertInstanceOf(ConversationStatus::class, $conversation->fresh()->status);
        $this->assertSame(ConversationStatus::Closed, $conversation->fresh()->status);
    }

    public function test_message_direction_enum_casts_correctly(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $message = Message::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
        ]);

        $this->assertInstanceOf(MessageDirection::class, $message->fresh()->direction);
        $this->assertSame(MessageDirection::Inbound, $message->fresh()->direction);
    }

    // --- Tenant isolation -------------------------------------------------

    public function test_restaurant_a_cannot_query_restaurant_bs_conversations(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);
        $conversationA = Conversation::factory()->create(['restaurant_id' => $restaurantA->id, 'customer_id' => $customerA->id]);
        Conversation::factory()->create(['restaurant_id' => $restaurantB->id, 'customer_id' => $customerB->id]);

        app(TenantContext::class)->set($restaurantA);

        $this->assertSame([$conversationA->id], Conversation::query()->pluck('id')->all());
    }

    public function test_restaurant_a_cannot_query_restaurant_bs_messages(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);
        $conversationA = Conversation::factory()->create(['restaurant_id' => $restaurantA->id, 'customer_id' => $customerA->id]);
        $conversationB = Conversation::factory()->create(['restaurant_id' => $restaurantB->id, 'customer_id' => $customerB->id]);
        $messageA = Message::factory()->create(['restaurant_id' => $restaurantA->id, 'conversation_id' => $conversationA->id]);
        Message::factory()->create(['restaurant_id' => $restaurantB->id, 'conversation_id' => $conversationB->id]);

        app(TenantContext::class)->set($restaurantA);

        $this->assertSame([$messageA->id], Message::query()->pluck('id')->all());
    }

    // --- Database integrity (raw inserts, bypassing Eloquent) -----------

    public function test_the_database_rejects_a_conversation_whose_restaurant_does_not_match_its_customers_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);

        $this->expectException(QueryException::class);

        DB::table('conversations')->insert([
            'restaurant_id' => $restaurantB->id,
            'customer_id' => $customerA->id,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_allows_a_conversation_whose_restaurant_matches_its_customers_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        DB::table('conversations')->insert([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('conversations', [
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_the_database_rejects_a_message_whose_restaurant_does_not_match_its_conversations_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $conversationA = Conversation::factory()->create(['restaurant_id' => $restaurantA->id, 'customer_id' => $customerA->id]);

        $this->expectException(QueryException::class);

        DB::table('messages')->insert([
            'restaurant_id' => $restaurantB->id,
            'conversation_id' => $conversationA->id,
            'direction' => 'inbound',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_allows_a_message_whose_restaurant_matches_its_conversations_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        DB::table('messages')->insert([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('messages', [
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
        ]);
    }

    public function test_the_database_rejects_a_conversation_assigned_to_a_user_from_another_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $userB = User::factory()->create(['restaurant_id' => $restaurantB->id]);

        $this->expectException(QueryException::class);

        DB::table('conversations')->insert([
            'restaurant_id' => $restaurantA->id,
            'customer_id' => $customerA->id,
            'assigned_user_id' => $userB->id,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_duplicate_provider_message_id_within_the_same_restaurant_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        Message::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'provider_message_id' => 'wamid.DUPLICATE',
        ]);

        $this->expectException(QueryException::class);

        DB::table('messages')->insert([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'provider_message_id' => 'wamid.DUPLICATE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_same_provider_message_id_is_allowed_in_different_restaurants(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);
        $conversationA = Conversation::factory()->create(['restaurant_id' => $restaurantA->id, 'customer_id' => $customerA->id]);
        $conversationB = Conversation::factory()->create(['restaurant_id' => $restaurantB->id, 'customer_id' => $customerB->id]);

        Message::factory()->create([
            'restaurant_id' => $restaurantA->id,
            'conversation_id' => $conversationA->id,
            'provider_message_id' => 'wamid.SHARED',
        ]);

        Message::factory()->create([
            'restaurant_id' => $restaurantB->id,
            'conversation_id' => $conversationB->id,
            'provider_message_id' => 'wamid.SHARED',
        ]);

        $this->assertSame(2, Message::query()->where('provider_message_id', 'wamid.SHARED')->count());
    }

    public function test_multiple_null_provider_message_ids_are_allowed_within_the_same_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        Message::factory()->create(['restaurant_id' => $restaurant->id, 'conversation_id' => $conversation->id, 'provider_message_id' => null]);
        Message::factory()->create(['restaurant_id' => $restaurant->id, 'conversation_id' => $conversation->id, 'provider_message_id' => null]);

        $this->assertSame(2, Message::query()->whereNull('provider_message_id')->count());
    }
}
