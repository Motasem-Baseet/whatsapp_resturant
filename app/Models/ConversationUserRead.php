<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (conversation, user) pair, recording when that specific
 * user last read that specific conversation. Deliberately per-user
 * rather than a global `is_read` column on conversations - the same
 * conversation is independently read/unread for each authorized user
 * viewing the shared restaurant inbox.
 */
class ConversationUserRead extends Model
{
    /** @use HasFactory<\Database\Factories\ConversationUserReadFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * restaurant_id is deliberately excluded - always assigned explicitly
     * by MarkConversationAsRead from the conversation's own restaurant,
     * matching the pattern used by every other tenant-scoped write in
     * this codebase (CreateMessage, CreateConversation, CreateCustomer).
     *
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'user_id',
        'last_read_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
