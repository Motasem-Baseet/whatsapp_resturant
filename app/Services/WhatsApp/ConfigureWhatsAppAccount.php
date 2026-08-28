<?php

namespace App\Services\WhatsApp;

use App\Models\Restaurant;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Str;

/**
 * The single place that writes WhatsAppAccount configuration - shared by
 * both the `whatsapp:configure` console command and the owner-only
 * settings UI, so the two entry points can never drift into different
 * behavior.
 *
 * Receives an already-resolved $account (new or existing) rather than
 * resolving one itself: the command identifies "which account" by
 * phone_number_id (a global identity, looked up with the tenant scope
 * bypassed, since console context has no authenticated tenant), while
 * the settings UI identifies it by "the current restaurant's own
 * account" (a tenant identity, via $restaurant->whatsAppAccounts()).
 * Those are genuinely different resolution strategies for different
 * callers - only the actual field-assignment logic below is shared.
 *
 * access_token is intentionally not in WhatsAppAccount::$fillable (see
 * that model's docblock) - it is set here via direct property
 * assignment, and only when a non-blank value is actually supplied, so
 * a blank "replace token" input on the edit form can never overwrite an
 * already-configured token with an empty string.
 */
class ConfigureWhatsAppAccount
{
    /**
     * @param  array{
     *     phone_number_id?: ?string,
     *     business_account_id?: ?string,
     *     display_phone_number?: ?string,
     *     access_token?: ?string,
     *     verify_token?: ?string,
     *     app_secret?: ?string,
     *     is_active?: bool,
     * }  $data  A blank/null access_token, verify_token, or app_secret
     *           leaves that field's existing stored value untouched. A
     *           blank verify_token on a brand-new account is replaced
     *           with a random one (the account's verify_token is NOT
     *           NULL at the database level, so it can never be left
     *           empty).
     */
    public function handle(Restaurant $restaurant, WhatsAppAccount $account, array $data): WhatsAppAccount
    {
        $account->restaurant_id = $restaurant->id;

        if (array_key_exists('phone_number_id', $data) && $data['phone_number_id'] !== null) {
            $account->phone_number_id = $data['phone_number_id'];
        }

        if (array_key_exists('business_account_id', $data)) {
            $account->business_account_id = $data['business_account_id'];
        }

        if (array_key_exists('display_phone_number', $data)) {
            $account->display_phone_number = $data['display_phone_number'];
        }

        if (! empty($data['access_token'])) {
            $account->access_token = $data['access_token'];
        }

        if (! empty($data['app_secret'])) {
            $account->app_secret = $data['app_secret'];
        }

        $account->verify_token = ! empty($data['verify_token'])
            ? $data['verify_token']
            : ($account->verify_token ?: Str::random(32));

        if (array_key_exists('is_active', $data)) {
            $account->is_active = $data['is_active'];
        }

        $account->save();

        return $account;
    }
}
