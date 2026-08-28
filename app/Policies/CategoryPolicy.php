<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * An owner may manage only their own restaurant's categories. Category
 * deletion is not implemented in this phase, so no delete/restore/
 * forceDelete methods are defined here.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('owner');
    }

    public function view(User $user, Category $category): bool
    {
        return $this->owns($user, $category);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('owner');
    }

    public function update(User $user, Category $category): bool
    {
        return $this->owns($user, $category);
    }

    protected function owns(User $user, Category $category): bool
    {
        return $user->hasRole('owner')
            && $user->restaurant_id !== null
            && $user->restaurant_id === $category->restaurant_id;
    }
}
