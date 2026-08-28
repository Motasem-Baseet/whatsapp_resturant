<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Models\WhatsAppAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Provisions (or updates) a restaurant's WhatsApp Cloud API account.
 * A console command rather than a settings UI - this is server-admin
 * configuration (access tokens, app secrets), not something an owner
 * or cashier should ever see or edit through the browser.
 */
class ConfigureWhatsAppAccount extends Command
{
    protected $signature = 'whatsapp:configure
        {restaurant : The restaurant ID to configure}
        {phone_number_id : The WhatsApp Cloud API phone_number_id}
        {access_token : The permanent or temporary access token}
        {--business-account-id= : The WhatsApp business account id}
        {--display-phone-number= : The human-readable phone number}
        {--app-secret= : The Meta app secret, for X-Hub-Signature-256 verification}
        {--verify-token= : The webhook verification token (generated randomly if omitted)}
        {--inactive : Create the account as inactive}';

    protected $description = 'Create or update a restaurant\'s WhatsApp Cloud API account';

    public function handle(): int
    {
        $restaurant = Restaurant::find($this->argument('restaurant'));

        if (! $restaurant) {
            $this->error('Restaurant not found.');

            return self::FAILURE;
        }

        $account = WhatsAppAccount::withoutGlobalScopes()
            ->firstOrNew(['phone_number_id' => $this->argument('phone_number_id')]);

        $account->restaurant_id = $restaurant->id;
        $account->access_token = $this->argument('access_token');
        $account->business_account_id = $this->option('business-account-id');
        $account->display_phone_number = $this->option('display-phone-number');
        $account->app_secret = $this->option('app-secret');
        $account->verify_token = $this->option('verify-token') ?: ($account->verify_token ?: Str::random(32));
        $account->is_active = ! $this->option('inactive');
        $account->save();

        $this->info("WhatsApp account configured for restaurant #{$restaurant->id}.");
        $this->line("verify_token: {$account->verify_token}");

        return self::SUCCESS;
    }
}
