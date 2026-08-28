<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable // implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * Default attribute values for new, in-memory instances.
     *
     * is_active mirrors the database column's own default (true), so a
     * freshly created model already reflects it in PHP without needing a
     * round-trip refresh from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    /**
     * The restaurant this user belongs to, if any.
     *
     * Nullable so the architecture can support a future platform-level
     * super-admin who is not tied to a single restaurant.
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * The orders this user created (Order::createdBy()'s inverse) - used
     * by the staff activity summary on the employee edit page.
     */
    public function createdOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by');
    }

    /**
     * The conversations assigned to this user
     * (Conversation::assignedUser()'s inverse) - used by the staff
     * activity summary on the employee edit page.
     */
    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_user_id');
    }
}
