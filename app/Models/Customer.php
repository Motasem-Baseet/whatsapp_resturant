<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * restaurant_id is deliberately excluded - it is always assigned
     * explicitly by the creating service, never from input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'phone',
        'notes',
    ];

    /**
     * Trim surrounding whitespace from the phone number on the way in,
     * regardless of which code path sets it.
     */
    protected function phone(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => trim($value),
        );
    }

    /**
     * The conversations with this customer.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * The orders placed by this customer.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
