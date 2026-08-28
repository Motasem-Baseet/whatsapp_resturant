<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Order;

/**
 * Enforces the order status lifecycle server-side. The transition rules
 * themselves live on OrderStatus::allowedTransitions() rather than
 * here, so there is exactly one place that defines what's legal.
 */
class UpdateOrderStatus
{
    /**
     * @throws InvalidOrderStatusTransitionException if the transition is
     *         not allowed from the order's current status.
     */
    public function handle(Order $order, OrderStatus $status): Order
    {
        if (! $order->status->canTransitionTo($status)) {
            throw InvalidOrderStatusTransitionException::from($order->status, $status);
        }

        $order->status = $status;
        $order->save();

        return $order;
    }
}
