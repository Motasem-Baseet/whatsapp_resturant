<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on a restaurant-scoped private channel only, mirroring
 * MessageCreated - never a global/public channel, so one tenant's order
 * activity can never be observed by another tenant's browser session.
 * Fired once from UpdateOrderStatus after every successful transition,
 * so both the owner/cashier and kitchen entry points are covered by
 * this single event with no duplication.
 *
 * The payload is deliberately minimal (id + new status only) - no
 * customer name, no totals, no notes - since the channel is shared by
 * owner, cashier, and kitchen subscribers alike, and kitchen has never
 * had access to order financials.
 */
class OrderStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("restaurants.{$this->order->restaurant_id}.orders"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.status-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->order->id,
            'status' => $this->order->status->value,
        ];
    }
}
