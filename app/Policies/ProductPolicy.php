<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * An owner may manage only their own restaurant's products. Product
 * deletion is not implemented in this phase, so no delete/restore/
 * forceDelete methods are defined here.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('owner');
    }

    public function view(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('owner');
    }

    public function update(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    protected function owns(User $user, Product $product): bool
    {
        return $user->hasRole('owner')
            && $user->restaurant_id !== null
            && $user->restaurant_id === $product->restaurant_id;
    }
}
