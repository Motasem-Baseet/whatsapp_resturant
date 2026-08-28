<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Restaurant;
use App\Models\User;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Phase 11 explicitly asked us to inspect the existing assignment
 * feature (AssignConversation, already covered by
 * tests/Feature/Inbox/InboxManagementTest.php since Phase 6/9 - eligible
 * assignee rules, kitchen rejection, cross-restaurant rejection) before
 * changing anything. Unassignment already existed there too
 * (AssignConversation::handle() accepts a null assignee), just never had
 * its own test - these tests cover that gap plus reassignment safety,
 * without duplicating the existing coverage.
 */
class ConversationAssignmentTest extends TestCase
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

    public function test_owner_can_unassign_an_assigned_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'assigned_user_id' => $owner->id,
        ]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->set('assigned_user_id', '')
            ->call('assign')
            ->assertHasNoErrors();

        $this->assertNull($conversation->fresh()->assigned_user_id);
    }

    public function test_owner_can_reassign_from_one_eligible_user_to_another(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'assigned_user_id' => $owner->id,
        ]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->set('assigned_user_id', (string) $cashier->id)
            ->call('assign')
            ->assertHasNoErrors();

        $this->assertSame($cashier->id, $conversation->fresh()->assigned_user_id);
    }

    public function test_cashier_can_unassign_a_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'assigned_user_id' => $owner->id,
        ]);

        $this->actingAs($cashier);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->set('assigned_user_id', '')
            ->call('assign')
            ->assertHasNoErrors();

        $this->assertNull($conversation->fresh()->assigned_user_id);
    }

    public function test_the_assignment_ui_clearly_shows_the_current_assignee_name(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'assigned_user_id' => $owner->id,
        ]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->assertSee($owner->name);
    }

    public function test_the_assignment_ui_clearly_shows_unassigned(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'assigned_user_id' => null,
        ]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->assertSee(__('Unassigned'));
    }
}
