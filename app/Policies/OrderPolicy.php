<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Owner and cashier both get full order-management access for this
 * foundation phase (view, create, update status); kitchen gets none of
 * it yet - a future kitchen-facing workflow is expected to use its own,
 * narrower policy rather than widening this one.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasOrderAccess($user);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    public function create(User $user): bool
    {
        return $this->hasOrderAccess($user);
    }

    public function update(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    protected function hasOrderAccess(User $user): bool
    {
        return $user->hasRole('owner') || $user->hasRole('cashier');
    }

    protected function owns(User $user, Order $order): bool
    {
        return $this->hasOrderAccess($user)
            && $user->restaurant_id !== null
            && $user->restaurant_id === $order->restaurant_id;
    }
}
