<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

        DB::transaction(function () use ($order, $status, $changedBy, $cancellationReason, $from) {
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

            // Only a *successful* transition into Cancelled restores
            // stock (Phase 27) - the canTransitionTo() guard above
            // already rejects an invalid transition (including a retry
            // on an already-cancelled order, since Cancelled has no
            // allowed outgoing transitions) before this ever runs, so
            // stock can never be restored twice for the same order.
            if ($status === OrderStatus::Cancelled) {
                $this->restoreStock($order);
            }
        });

        OrderStatusUpdated::dispatch($order);

        return $order;
    }

    /**
     * Restores stock_quantity for every line item that actually had
     * stock deducted at order-creation time (OrderItem::stock_deducted)
     * - never inferred from the product's *current* stock-tracking
     * state, so this stays correct even if the product was later
     * switched into/out of stock tracking, deactivated, or made
     * unavailable. An item whose product no longer resolves (there is
     * no product-deletion feature in this app, but OrderItem::product()
     * is nullable by design) is safely skipped.
     *
     * Always scoped through $order->restaurant->products() - never a
     * bare Product::find() - so stock restoration can never reach
     * another restaurant's product even if a product id were ever
     * corrupted. Each product is row-locked before incrementing, for
     * the same concurrency-safety reason CreateOrder locks it before
     * decrementing.
     */
    private function restoreStock(Order $order): void
    {
        $items = $order->items()->where('stock_deducted', true)->get();

        foreach ($items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $product = $order->restaurant->products()
                ->whereKey($item->product_id)
                ->lockForUpdate()
                ->first();

            $product?->increment('stock_quantity', $item->quantity);
        }
    }
}
