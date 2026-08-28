<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A restaurant's WhatsApp Cloud API configuration. A restaurant may have
 * more than one (e.g. a future second line), so this is a plain hasMany
 * relationship rather than a one-account assumption.
 *
 * Webhook requests carry no authenticated user, so they cannot rely on
 * TenantContext/BelongsToRestaurant's auto-scoping the way normal
 * browser requests do. Account lookup for webhook processing must
 * always be explicit:
 *  - GET verification: WhatsAppAccount::where('verify_token', ...)
 *  - POST delivery: WhatsAppAccount::where('phone_number_id', ...)
 * Both queries run before any tenant context exists, and their result
 * is what establishes the tenant context for everything downstream -
 * never the other way around.
 */
class WhatsAppAccount extends Model
{
    /** @use HasFactory<\Database\Factories\WhatsAppAccountFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * Laravel's default table-name inference would snake-case this to
     * "whats_app_accounts" (it treats the internal capital in
     * "WhatsApp" as a separate word), which does not match the
     * migration's actual table name - set explicitly to avoid that.
     */
    protected $table = 'whatsapp_accounts';

    /**
     * The attributes that are mass assignable.
     *
     * restaurant_id is deliberately excluded (assigned explicitly by
     * the provisioning command, never from input). access_token is
     * deliberately excluded too, per the explicit requirement that it
     * must never be settable via mass assignment - it is only ever set
     * via direct property assignment from trusted, server-side code
     * (the whatsapp:configure command).
     *
     * @var list<string>
     */
    protected $fillable = [
        'phone_number_id',
        'business_account_id',
        'display_phone_number',
        'verify_token',
        'app_secret',
        'is_active',
    ];

    /**
     * Secrets that must never leak into array/JSON serialization
     * (accidental API responses, logging a whole model, etc.).
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'app_secret',
        'verify_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }
}
