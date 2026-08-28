<?php

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

/**
 * Owner and cashier both get full order-management access for this
 * foundation phase (view, create, update status). Kitchen gets its own
 * separate trio of methods below (viewAnyAsKitchen/viewAsKitchen/
 * canTransitionAsKitchen) rather than being folded into viewAny/view/
 * update - this keeps owner/cashier behavior provably untouched and
 * keeps every kitchen-only rule in one easy-to-find place.
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

    /**
     * Historical order reporting/analytics (Phase 23) is owner-only,
     * unlike general order viewAny/view/update - it surfaces
     * restaurant-wide financial data (revenue, average order value,
     * top customers' total spend) that this codebase has consistently
     * kept owner-only elsewhere (see EmployeePolicy, ProductPolicy,
     * CategoryPolicy's own viewAny). A separate ability rather than
     * folding this into viewAny keeps that existing owner|cashier rule
     * for ordinary order access completely unchanged.
     */
    public function viewReports(User $user): bool
    {
        return $user->hasRole('owner');
    }

    protected function owns(User $user, Order $order): bool
    {
        return $this->hasOrderAccess($user)
            && $user->restaurant_id !== null
            && $user->restaurant_id === $order->restaurant_id;
    }

    /**
     * Kitchen may view the kitchen order list for their own restaurant.
     */
    public function viewAnyAsKitchen(User $user): bool
    {
        return $user->hasRole('kitchen');
    }

    /**
     * Kitchen may view a specific order only within their own
     * restaurant. This does not restrict by order status - the kitchen
     * order *list* is what narrows visible orders to the
     * confirmed/preparing/ready statuses relevant to the workflow.
     */
    public function viewAsKitchen(User $user, Order $order): bool
    {
        return $user->hasRole('kitchen')
            && $user->restaurant_id !== null
            && $user->restaurant_id === $order->restaurant_id;
    }

    /**
     * The exact transitions the kitchen interface may perform - a
     * strict subset of OrderStatus::allowedTransitions() covering only
     * confirmed -> preparing and preparing -> ready. This does not
     * change what the domain considers a valid transition (OrderStatus
     * remains the single source of truth for that); it only narrows
     * which of those valid transitions the kitchen role may trigger.
     * Kitchen can never cancel or complete an order here, even though
     * OrderStatus itself allows those transitions for owner/cashier.
     */
    public function canTransitionAsKitchen(User $user, Order $order, OrderStatus $target): bool
    {
        if (! $this->viewAsKitchen($user, $order)) {
            return false;
        }

        return match ($order->status) {
            OrderStatus::Confirmed => $target === OrderStatus::Preparing,
            OrderStatus::Preparing => $target === OrderStatus::Ready,
            default => false,
        };
    }
}
