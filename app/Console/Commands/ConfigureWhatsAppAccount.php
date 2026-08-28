<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\ConfigureWhatsAppAccount as ConfigureWhatsAppAccountService;
use Illuminate\Console\Command;

/**
 * Provisions (or updates) a restaurant's WhatsApp Cloud API account.
 * A console command, kept alongside the Phase 14 settings UI for
 * server-admin/deployment use - both now share their actual
 * field-assignment logic via App\Services\WhatsApp\
 * ConfigureWhatsAppAccount, so they cannot drift into different
 * behavior. This command still prints the verify_token directly to the
 * operator's own terminal (a trusted server-admin context); the web UI
 * deliberately never redisplays it - see that service's docblock.
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

        app(ConfigureWhatsAppAccountService::class)->handle($restaurant, $account, [
            'phone_number_id' => $this->argument('phone_number_id'),
            'access_token' => $this->argument('access_token'),
            'business_account_id' => $this->option('business-account-id'),
            'display_phone_number' => $this->option('display-phone-number'),
            'app_secret' => $this->option('app-secret'),
            'verify_token' => $this->option('verify-token'),
            'is_active' => ! $this->option('inactive'),
        ]);

        $this->info("WhatsApp account configured for restaurant #{$restaurant->id}.");
        $this->line("verify_token: {$account->verify_token}");

        return self::SUCCESS;
    }
}
