<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Phase 17 deliberately splits "viewing" from "managing": owner and
 * cashier may both view the customer list and a customer's profile
 * (matching Order/Conversation's existing owner-or-cashier access
 * model), but creating/editing a customer record remains owner-only,
 * unchanged from Phase 5 - Phase 17 is read/discovery-focused and does
 * not touch customer identity management.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasViewAccess($user);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->hasViewAccess($user) && $this->belongsToSameRestaurant($user, $customer);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('owner');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->hasRole('owner') && $this->belongsToSameRestaurant($user, $customer);
    }

    protected function hasViewAccess(User $user): bool
    {
        return $user->hasRole('owner') || $user->hasRole('cashier');
    }

    protected function belongsToSameRestaurant(User $user, Customer $customer): bool
    {
        return $user->restaurant_id !== null
            && $user->restaurant_id === $customer->restaurant_id;
    }
}
