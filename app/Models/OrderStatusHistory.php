<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable audit row recorded by UpdateOrderStatus every time an
 * order transition succeeds. Rows are never updated or deleted by the
 * application, so this doubles as the order's status timeline.
 */
class OrderStatusHistory extends Model
{
    /** @use HasFactory<\Database\Factories\OrderStatusHistoryFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * restaurant_id is deliberately excluded - always assigned directly
     * by UpdateOrderStatus from the order's own restaurant_id, never
     * accepted from input, matching Order/OrderItem's own convention.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'changed_by',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The user who made the change, if any. Intentionally not
     * restricted by the actor's current active/inactive state - the
     * history must keep pointing at who actually made the change even
     * if that staff member is later deactivated.
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
