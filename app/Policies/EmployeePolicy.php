<?php

namespace App\Policies;

use App\Models\User;

/**
 * Governs an owner managing another user as an "employee" of their
 * restaurant (list, create, edit).
 *
 * This is deliberately not the general-purpose UserPolicy - it only
 * governs the owner -> employee management relationship, registered
 * explicitly against the User model in AppServiceProvider since it does
 * not follow Laravel's UserPolicy naming convention.
 */
class EmployeePolicy
{
    /**
     * Any authenticated owner can view their own restaurant's employee list.
     */
    public function viewAny(User $owner): bool
    {
        return $owner->hasRole('owner');
    }

    /**
     * An owner may view a specific employee only if that employee belongs
     * to their own restaurant, and is not itself an owner.
     */
    public function view(User $owner, User $employee): bool
    {
        return $this->manages($owner, $employee);
    }

    /**
     * Any authenticated owner can create employees for their own restaurant.
     */
    public function create(User $owner): bool
    {
        return $owner->hasRole('owner');
    }

    /**
     * An owner may edit a specific employee only if that employee belongs
     * to their own restaurant, and is not itself an owner - so an owner
     * can never edit another owner (including themselves) through this
     * screen.
     */
    public function update(User $owner, User $employee): bool
    {
        return $this->manages($owner, $employee);
    }

    protected function manages(User $owner, User $employee): bool
    {
        return $owner->hasRole('owner')
            && $owner->restaurant_id !== null
            && $owner->restaurant_id === $employee->restaurant_id
            && ! $employee->hasRole('owner');
    }
}
