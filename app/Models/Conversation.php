<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Per-user read-state rows for this conversation - see
     * ConversationUserRead's class docblock for why this is per user
     * rather than a single column on this model.
     */
    public function reads(): HasMany
    {
        return $this->hasMany(ConversationUserRead::class);
    }

    /**
     * Conversations that are unread for the given user: either the user
     * has never read the conversation at all, or at least one message
     * (of any direction) was created after their last_read_at.
     *
     * A conversation the user sent the newest message in is not
     * "unread" for them - MarkConversationAsRead is called for the
     * sender at send time, advancing their own last_read_at past that
     * message, so this query needs no special-casing by direction here.
     *
     * Implemented as a single LEFT JOIN (bounded by the read table's own
     * unique(conversation_id, user_id), so it can never fan out extra
     * rows) plus one EXISTS subquery against messages - not a per-row
     * query, so this stays efficient across many conversations. The
     * explicit select('conversations.*') is required once a join is
     * added, to stop the joined table's same-named columns (id,
     * created_at, ...) from silently overwriting the conversation's own
     * attributes during hydration.
     */
    public function scopeUnreadFor(Builder $query, User $user): Builder
    {
        return $query
            ->select('conversations.*')
            ->leftJoin('conversation_user_reads', function ($join) use ($user) {
                $join->on('conversation_user_reads.conversation_id', '=', 'conversations.id')
                    ->where('conversation_user_reads.user_id', '=', $user->id);
            })
            ->whereExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->where(function ($q) {
                        $q->whereNull('conversation_user_reads.last_read_at')
                            ->orWhereColumn('messages.created_at', '>', 'conversation_user_reads.last_read_at');
                    });
            });
    }
}
