<?php

namespace App\Services\Inbox;

use App\Models\Conversation;
use App\Models\ConversationUserRead;
use App\Models\User;
use InvalidArgumentException;

/**
 * Records that the given user has read the given conversation as of
 * now. Idempotent by design (updateOrCreate against the
 * unique(conversation_id, user_id) constraint) - calling this again for
 * the same user/conversation just advances last_read_at, it never
 * creates a second row or errors.
 *
 * Only ever affects the one (conversation, user) pair passed in - other
 * users' read state is untouched, matching the per-user read model
 * (see ConversationUserRead).
 */
class MarkConversationAsRead
{
    /**
     * @throws InvalidArgumentException if the user does not belong to the
     *         conversation's restaurant.
     */
    public function handle(Conversation $conversation, User $user): ConversationUserRead
    {
        if ($user->restaurant_id !== $conversation->restaurant_id) {
            throw new InvalidArgumentException('The user must belong to the same restaurant as the conversation.');
        }

        // updateOrCreate()'s "update" values go through fill(), which
        // respects $fillable - and restaurant_id is deliberately not
        // fillable (see the model). So it is set directly here, the
        // same way CreateMessage/CreateConversation/CreateCustomer
        // assign restaurant_id explicitly rather than via mass
        // assignment.
        $read = ConversationUserRead::query()->firstOrNew([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        $read->restaurant_id = $conversation->restaurant_id;
        $read->last_read_at = now();
        $read->save();

        return $read;
    }
}
