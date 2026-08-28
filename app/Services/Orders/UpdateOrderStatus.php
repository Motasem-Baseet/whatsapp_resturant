<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;

/**
 * Enforces the order status lifecycle server-side. The transition rules
 * themselves live on OrderStatus::allowedTransitions() rather than
 * here, so there is exactly one place that defines what's legal.
 *
 * Shared by every entry point that changes an order's status (the
 * owner/cashier order page and the kitchen order page), so recording
 * history and broadcasting the change both happen exactly once here,
 * never duplicated per caller.
 */
class UpdateOrderStatus
{
    /**
     * @param  User|null  $changedBy  The acting user, for the audit
     *         trail only - never used for authorization, which callers
     *         must already have performed before reaching this service.
     *         Optional so existing/direct callers (tests, tooling)
     *         remain valid; a transition made with no acting user is
     *         recorded with a null changed_by.
     * @param  string|null  $cancellationReason  Optional free-text
     *         reason, persisted on the order itself only when given -
     *         only meaningful for a transition into Cancelled, but not
     *         restricted to it here; the caller decides when it applies.
     *         Never written if the transition below is rejected, so a
     *         failed cancellation can never leave a reason behind.
     *
     * @throws InvalidOrderStatusTransitionException if the transition is
     *         not allowed from the order's current status.
     */
    public function handle(Order $order, OrderStatus $status, ?User $changedBy = null, ?string $cancellationReason = null): Order
    {
        if (! $order->status->canTransitionTo($status)) {
            throw InvalidOrderStatusTransitionException::from($order->status, $status);
        }

        $from = $order->status;

        $order->status = $status;

        if ($cancellationReason !== null) {
            $order->cancellation_reason = $cancellationReason;
        }

        $order->save();

        $history = new OrderStatusHistory([
            'order_id' => $order->id,
            'from_status' => $from->value,
            'to_status' => $status->value,
            'changed_by' => $changedBy?->id,
        ]);
        $history->restaurant_id = $order->restaurant_id;
        $history->save();

        OrderStatusUpdated::dispatch($order);

        return $order;
    }
}
