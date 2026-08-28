<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * An owner may manage only their own restaurant's customers. Customer
 * deletion is not implemented in this phase, so no delete/restore/
 * forceDelete methods are defined here.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('owner');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->owns($user, $customer);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('owner');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->owns($user, $customer);
    }

    protected function owns(User $user, Customer $customer): bool
    {
        return $user->hasRole('owner')
            && $user->restaurant_id !== null
            && $user->restaurant_id === $customer->restaurant_id;
    }
}
