<?php

namespace App\Services\Inbox;

use App\Models\Conversation;
use App\Models\User;
use InvalidArgumentException;

/**
 * Unlike the other Inbox services, this one enforces assignee ownership,
 * role eligibility, and active status itself rather than only trusting
 * validated input - assignment rules are easy to get subtly wrong from a
 * new call site (a future job, console command, or webhook handler), so
 * the guarantee lives here rather than only in the Livewire form.
 *
 * The "same restaurant" half of this is also backed by the database's
 * composite foreign key on (assigned_user_id, restaurant_id), so even a
 * direct/raw write bypassing this service entirely cannot cross
 * restaurants. Role eligibility (no kitchen users) and active status have
 * no database equivalent - roles are assigned dynamically via Spatie, and
 * is_active is an ordinary boolean column with no constraint tying it to
 * assignment - so both can only be enforced here and in validation.
 */
class AssignConversation
{
    /**
     * @param  User|null  $assignee  Pass null to clear the assignment.
     *
     * @throws InvalidArgumentException if the assignee does not belong to
     *         the conversation's restaurant, holds an ineligible role, or
     *         is deactivated.
     */
    public function handle(Conversation $conversation, ?User $assignee): Conversation
    {
        if ($assignee !== null) {
            if ($assignee->restaurant_id !== $conversation->restaurant_id) {
                throw new InvalidArgumentException('The assignee must belong to the same restaurant as the conversation.');
            }

            if (! $assignee->hasRole('owner') && ! $assignee->hasRole('cashier')) {
                throw new InvalidArgumentException('Only owner or cashier users may be assigned to a conversation.');
            }

            // A deactivated employee must not be newly (re)assigned to a
            // conversation - this only blocks new assignment actions
            // through this method; an existing assignment to a
            // since-deactivated employee is left untouched, since nothing
            // here mutates a conversation this method isn't called for.
            if (! $assignee->is_active) {
                throw new InvalidArgumentException('Only active owner or cashier users may be assigned to a conversation.');
            }
        }

        $conversation->assigned_user_id = $assignee?->id;
        $conversation->save();

        return $conversation;
    }
}
