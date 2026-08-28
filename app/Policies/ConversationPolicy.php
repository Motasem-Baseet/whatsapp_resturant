<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Owner and cashier both get full inbox access for the foundation built
 * in this phase (view, create, update/assign); kitchen gets none of it.
 * Object-level access additionally requires the conversation to belong
 * to the acting user's own restaurant.
 */
class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasInboxAccess($user);
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function create(User $user): bool
    {
        return $this->hasInboxAccess($user);
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    protected function hasInboxAccess(User $user): bool
    {
        return $user->hasRole('owner') || $user->hasRole('cashier');
    }

    protected function owns(User $user, Conversation $conversation): bool
    {
        return $this->hasInboxAccess($user)
            && $user->restaurant_id !== null
            && $user->restaurant_id === $conversation->restaurant_id;
    }
}
