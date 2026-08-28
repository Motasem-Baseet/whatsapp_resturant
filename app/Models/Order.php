<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * restaurant_id, subtotal, and total are deliberately excluded -
     * restaurant_id is always assigned by BelongsToRestaurant/the
     * creating service, and subtotal/total are always calculated
     * server-side by CreateOrder, never accepted from input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'conversation_id',
        'created_by',
        'status',
        'notes',
    ];

    /**
     * Default attribute values for new, in-memory instances.
     *
     * status mirrors the database column's own default ('pending'), so
     * a freshly created model already reflects it in PHP without
     * needing a round-trip refresh from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The audit trail of status transitions, recorded by
     * UpdateOrderStatus on every successful change.
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    // --- Operational attention (Phase 21) -------------------------------
    //
    // Single authoritative source for "does this order need attention"
    // across the order list, kitchen list, order detail pages, and the
    // dashboard - none of them re-implement this logic themselves, they
    // all call these methods. Attention state is always derived from
    // status + timing at read time; nothing is persisted.

    /**
     * Statuses considered operationally active. Completed and Cancelled
     * are terminal and can never require attention.
     *
     * @return list<OrderStatus>
     */
    public static function attentionEligibleStatuses(): array
    {
        return [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready];
    }

    /**
     * The string values of attentionEligibleStatuses(), for use directly
     * in a whereIn('status', ...) query clause.
     *
     * @return list<string>
     */
    public static function attentionEligibleStatusValues(): array
    {
        return array_map(fn (OrderStatus $status) => $status->value, self::attentionEligibleStatuses());
    }

    /**
     * When this order entered its current status. Derived from the most
     * recent order_status_histories row transitioning *into* the
     * current status, falling back to the order's own created_at for an
     * order that has never transitioned (still in its original pending
     * state - order_status_histories intentionally records only
     * transitions, not creation, per Phase 20).
     *
     * Reads from the statusHistory relation when it's already loaded,
     * rather than issuing its own query, so callers displaying this for
     * many orders at once (list pages) can eager-load statusHistory
     * once up front instead of causing a query per order.
     */
    public function currentStatusStartedAt(): Carbon
    {
        $history = $this->relationLoaded('statusHistory')
            ? $this->statusHistory
            : $this->statusHistory()->get();

        $latestIntoCurrentStatus = $history
            ->where('to_status', $this->status->value)
            ->sortBy([['created_at', 'desc'], ['id', 'desc']])
            ->first();

        return $latestIntoCurrentStatus?->created_at ?? $this->created_at;
    }

    /**
     * Minutes this order may remain in its current status before it's
     * considered to need attention (config/orders.php). Null for a
     * status that is never attention-eligible.
     */
    public function attentionThresholdMinutes(): ?int
    {
        return match ($this->status) {
            OrderStatus::Pending => (int) config('orders.attention_thresholds.pending'),
            OrderStatus::Confirmed, OrderStatus::Preparing => (int) config('orders.attention_thresholds.preparing'),
            OrderStatus::Ready => (int) config('orders.attention_thresholds.ready'),
            OrderStatus::Completed, OrderStatus::Cancelled => null,
        };
    }

    /**
     * Whether this order has been in its current status for at least
     * (>=) its configured threshold. "At least" is the deliberate rule
     * for the boundary - an order exactly at its threshold already
     * needs attention, not only once it exceeds it.
     */
    public function requiresAttention(): bool
    {
        $threshold = $this->attentionThresholdMinutes();

        if ($threshold === null) {
            return false;
        }

        return $this->currentStatusStartedAt()->diffInMinutes(now()) >= $threshold;
    }

    /**
     * A short, stable, machine-readable reason key for the current
     * attention state, or null when the order does not currently
     * require attention. Centralizing this here (rather than branching
     * on status in every Blade view that shows it) is what keeps the
     * order list, kitchen list, and detail pages from duplicating this
     * conditional logic themselves.
     */
    public function attentionReason(): ?string
    {
        if (! $this->requiresAttention()) {
            return null;
        }

        return match ($this->status) {
            OrderStatus::Pending => 'pending_too_long',
            OrderStatus::Confirmed => 'confirmed_too_long',
            OrderStatus::Preparing => 'preparing_too_long',
            OrderStatus::Ready => 'ready_too_long',
            default => null,
        };
    }

    /**
     * A human-readable message for attentionReason(), centralized here
     * so every page renders identical wording rather than each
     * reimplementing its own copy.
     */
    public function attentionMessage(): ?string
    {
        return match ($this->attentionReason()) {
            'pending_too_long' => 'Waiting to be confirmed or cancelled',
            'confirmed_too_long' => 'Waiting to start preparing',
            'preparing_too_long' => 'Taking longer than expected to prepare',
            'ready_too_long' => 'Waiting to be completed',
            default => null,
        };
    }
}
