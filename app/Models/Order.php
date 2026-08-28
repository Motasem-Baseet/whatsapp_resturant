<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
