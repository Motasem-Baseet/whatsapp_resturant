<?php

namespace Tests\Feature\Inbox;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Inbox\AssignConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InboxManagementTest extends TestCase
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

    // --- Conversation creation --------------------------------------------

    public function test_owner_can_create_a_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.create')
            ->set('customer_id', (string) $customer->id)
            ->call('save')
            ->assertHasNoErrors();

        $conversation = Conversation::where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame($owner->restaurant_id, $conversation->restaurant_id);
        $this->assertSame(ConversationStatus::Open, $conversation->status);
    }

    public function test_cashier_can_create_a_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($cashier);

        Volt::test('inbox.conversations.create')
            ->set('customer_id', (string) $customer->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('conversations', ['customer_id' => $customer->id, 'restaurant_id' => $restaurant->id]);
    }

    public function test_kitchen_cannot_create_a_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen);

        $this->get(route('conversations.create'))->assertForbidden();
        $this->get(route('inbox.index'))->assertForbidden();
    }

    public function test_conversation_customer_must_belong_to_the_current_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);

        $this->actingAs($ownerA);

        Volt::test('inbox.conversations.create')
            ->set('customer_id', (string) $customerB->id)
            ->call('save')
            ->assertHasErrors(['customer_id']);

        $this->assertDatabaseMissing('conversations', ['customer_id' => $customerB->id]);
    }

    // --- Tenant isolation / access -----------------------------------

    public function test_cross_tenant_conversation_route_returns_404(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);
        $conversationB = Conversation::factory()->create(['restaurant_id' => $restaurantB->id, 'customer_id' => $customerB->id]);

        $response = $this->actingAs($ownerA)->get(route('conversations.show', $conversationB));

        $response->assertNotFound();
    }

    public function test_cross_tenant_message_access_is_blocked(): void
    {
        // A restaurant A owner viewing their own conversation must never
        // see restaurant B's messages, even if (hypothetically) they
        // shared a conversation id collision - the messages relationship
        // itself is tenant-scoped via BelongsToRestaurant.
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);
        $conversationA = Conversation::factory()->create(['restaurant_id' => $restaurantA->id, 'customer_id' => $customerA->id]);
        $conversationB = Conversation::factory()->create(['restaurant_id' => $restaurantB->id, 'customer_id' => $customerB->id]);
        Message::factory()->create(['restaurant_id' => $restaurantA->id, 'conversation_id' => $conversationA->id, 'content' => 'Restaurant A message']);
        Message::factory()->create(['restaurant_id' => $restaurantB->id, 'conversation_id' => $conversationB->id, 'content' => 'Restaurant B message']);

        $ownerA = $this->createOwner($restaurantA);

        $response = $this->actingAs($ownerA)->get(route('conversations.show', $conversationA));

        $response->assertOk();
        $response->assertSee('Restaurant A message');
        $response->assertDontSee('Restaurant B message');
    }

    // --- Assignment -----------------------------------------------------

    public function test_owner_can_assign_a_same_restaurant_owner(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $otherOwner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->set('assigned_user_id', (string) $otherOwner->id)
            ->call('assign')
            ->assertHasNoErrors();

        $this->assertSame($otherOwner->id, $conversation->fresh()->assigned_user_id);
    }

    public function test_owner_can_assign_a_same_restaurant_cashier(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->set('assigned_user_id', (string) $cashier->id)
            ->call('assign')
            ->assertHasNoErrors();

        $this->assertSame($cashier->id, $conversation->fresh()->assigned_user_id);
    }

    public function test_kitchen_user_cannot_be_assigned(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->set('assigned_user_id', (string) $kitchen->id)
            ->call('assign')
            ->assertHasErrors(['assigned_user_id']);

        $this->assertNull($conversation->fresh()->assigned_user_id);
    }

    public function test_user_from_another_restaurant_cannot_be_assigned(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $ownerB = $this->createOwner($restaurantB);
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $conversationA = Conversation::factory()->create(['restaurant_id' => $restaurantA->id, 'customer_id' => $customerA->id]);

        $this->actingAs($ownerA);

        Volt::test('inbox.conversations.show', ['conversation' => $conversationA])
            ->set('assigned_user_id', (string) $ownerB->id)
            ->call('assign')
            ->assertHasErrors(['assigned_user_id']);

        $this->assertNull($conversationA->fresh()->assigned_user_id);
    }

    public function test_the_assign_conversation_service_itself_rejects_cross_restaurant_assignment(): void
    {
        // Defense in depth: even if a future caller bypasses the
        // Livewire form entirely, the service enforces this itself.
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $conversationA = Conversation::factory()->create(['restaurant_id' => $restaurantA->id, 'customer_id' => $customerA->id]);
        $userB = User::factory()->create(['restaurant_id' => $restaurantB->id]);
        $userB->assignRole(Role::findOrCreate('owner'));

        $this->expectException(InvalidArgumentException::class);

        app(AssignConversation::class)->handle($conversationA, $userB);
    }

    public function test_the_assign_conversation_service_itself_rejects_kitchen_role(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->expectException(InvalidArgumentException::class);

        app(AssignConversation::class)->handle($conversation, $kitchen);
    }

    // --- Inbox listing ------------------------------------------------

    public function test_owner_can_access_inbox(): void
    {
        $owner = $this->createOwner();

        $response = $this->actingAs($owner)->get(route('inbox.index'));

        $response->assertOk();
    }

    public function test_cashier_can_access_inbox(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');

        $response = $this->actingAs($cashier)->get(route('inbox.index'));

        $response->assertOk();
    }

    public function test_kitchen_receives_403_for_inbox(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $response = $this->actingAs($kitchen)->get(route('inbox.index'));

        $response->assertForbidden();
    }

    public function test_inbox_only_displays_current_restaurant_conversations(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id, 'name' => 'Customer A']);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id, 'name' => 'Customer B']);
        Conversation::factory()->create(['restaurant_id' => $restaurantA->id, 'customer_id' => $customerA->id]);
        Conversation::factory()->create(['restaurant_id' => $restaurantB->id, 'customer_id' => $customerB->id]);

        $response = $this->actingAs($ownerA)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertSee('Customer A');
        $response->assertDontSee('Customer B');
    }

    public function test_conversations_are_ordered_by_last_message_at_then_created_at(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $customerOld = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Old Activity']);
        $customerRecent = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Recent Activity']);
        $customerNone = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'No Activity']);

        Conversation::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customerOld->id,
            'last_message_at' => now()->subDays(2),
        ]);
        Conversation::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customerRecent->id,
            'last_message_at' => now()->subMinute(),
        ]);
        Conversation::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customerNone->id,
            'last_message_at' => null,
        ]);

        $this->actingAs($owner);

        $names = Volt::test('inbox.index')
            ->instance()
            ->conversations()
            ->pluck('customer.name')
            ->all();

        $this->assertSame(['Recent Activity', 'Old Activity', 'No Activity'], $names);
    }

    // --- Messages -------------------------------------------------------
    //
    // Local test messaging (sendLocalMessage) was replaced in Phase 9 by
    // real WhatsApp sending (sendMessage) - see tests/Feature/WhatsApp/
    // OutboundMessageTest.php for the full outbound-sending coverage.
    // These three regression tests exercise the same Livewire action via
    // its new, real send path.

    public function test_sent_message_gets_correct_restaurant_id(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->set('message_content', 'Hello there')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $message = Message::where('content', 'Hello there')->firstOrFail();

        $this->assertSame($restaurant->id, $message->restaurant_id);
        $this->assertSame($conversation->id, $message->conversation_id);
    }

    public function test_send_message_requires_content(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->set('message_content', '')
            ->call('sendMessage')
            ->assertHasErrors(['message_content']);

        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id]);
    }

    public function test_sending_a_message_updates_conversation_last_message_at(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'last_message_at' => null,
        ]);
        WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT2']]], 200)]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->set('message_content', 'Hello there')
            ->call('sendMessage');

        $this->assertNotNull($conversation->fresh()->last_message_at);
    }
}
