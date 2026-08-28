<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    /** @use HasFactory<\Database\Factories\ConversationFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * restaurant_id is deliberately excluded - it is always assigned by
     * BelongsToRestaurant from the current tenant, or explicitly by
     * CreateConversation, never from input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'assigned_user_id',
        'status',
        'last_message_at',
    ];

    /**
     * Default attribute values for new, in-memory instances.
     *
     * status mirrors the database column's own default ('open'), so a
     * freshly created model already reflects it in PHP without needing
     * a round-trip refresh from the database - the same gap fixed for
     * User::$is_active in Phase 3.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_message_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The orders that originated from this conversation.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
