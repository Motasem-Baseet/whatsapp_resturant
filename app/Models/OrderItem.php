<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    /** @use HasFactory<\Database\Factories\OrderItemFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * restaurant_id is deliberately excluded - it is always assigned
     * explicitly by CreateOrder from the owning order, never from
     * input. product_name, unit_price, and line_total are snapshots
     * computed server-side by CreateOrder - never accepted from the
     * client - but remain fillable since they are legitimate,
     * server-derived values passed internally, not raw request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'product_name',
        'unit_price',
        'quantity',
        'line_total',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'line_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The product this line item was created from, if it still exists.
     * Historical product_name/unit_price are preserved on this row
     * regardless of whether this relationship still resolves.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
