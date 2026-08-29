<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Inbox\AssignConversation;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

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

    /**
     * is_active is not mass-assignable on User (matching this
     * codebase's established convention), so it must be set directly
     * and saved rather than via update().
     */
    private function deactivate(User $user): void
    {
        $user->is_active = false;
        $user->save();
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

    // --- Inactive staff (Phase 25) ------------------------------------------

    public function test_a_deactivated_employee_does_not_appear_in_the_assignable_users_list(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $inactiveCashier = $this->createEmployee($restaurant, 'cashier');
        $this->deactivate($inactiveCashier);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->assertDontSee($inactiveCashier->name);
    }

    public function test_a_deactivated_employee_cannot_be_newly_assigned_via_a_forged_request(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $inactiveCashier = $this->createEmployee($restaurant, 'cashier');
        $this->deactivate($inactiveCashier);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->actingAs($owner);

        // The dropdown never offers the inactive cashier, but the
        // component must independently refuse them even if a client
        // forges the request with their id directly.
        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->set('assigned_user_id', (string) $inactiveCashier->id)
            ->call('assign')
            ->assertHasErrors(['assigned_user_id']);

        $this->assertNull($conversation->fresh()->assigned_user_id);
    }

    public function test_the_assign_conversation_service_itself_rejects_an_inactive_assignee(): void
    {
        // Defense in depth: even if a future caller bypasses the
        // Livewire form entirely, the service enforces this itself.
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $inactiveCashier = $this->createEmployee($restaurant, 'cashier');
        $this->deactivate($inactiveCashier);

        $this->expectException(InvalidArgumentException::class);

        app(AssignConversation::class)->handle($conversation, $inactiveCashier);
    }

    public function test_an_existing_assignment_survives_the_assignees_later_deactivation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'assigned_user_id' => $cashier->id,
        ]);

        $this->deactivate($cashier);

        // The historical assignment itself is never mutated just because
        // the assignee was later deactivated.
        $this->assertSame($cashier->id, $conversation->fresh()->assigned_user_id);

        $this->actingAs($owner);

        // The conversation detail page still correctly displays who it
        // is (historically) assigned to, even though that same name no
        // longer appears as a selectable option in the dropdown.
        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->assertSee($cashier->name);
    }
}
