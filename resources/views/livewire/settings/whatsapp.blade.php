<?php

use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\ConfigureWhatsAppAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Owner-only settings page for the restaurant's WhatsApp Cloud API
 * account (see App\Models\WhatsAppAccount and Phase 9's webhook/sending
 * architecture, both reused unchanged here).
 *
 * Deliberately does NOT hold the WhatsAppAccount model - or any of its
 * decrypted secret values - as a public property at any point. Every
 * action below re-resolves "the current restaurant's account" fresh
 * from Auth::user()->restaurant->whatsAppAccounts() rather than trusting
 * anything carried over in component state, and the only account-derived
 * public state is a handful of booleans (is a secret configured or not)
 * - never the secret itself. access_token/verify_token/app_secret below
 * are write-only "replacement value" inputs: always initialized empty,
 * never populated from the model, and reset to empty again after every
 * save.
 */
new #[Layout('components.layouts.app')] class extends Component {
    public string $phone_number_id = '';
    public string $business_account_id = '';
    public string $display_phone_number = '';
    public bool $is_active = true;

    /**
     * Write-only replacement inputs - see the class docblock. Left
     * blank, each preserves the corresponding stored secret unchanged
     * (enforced by ConfigureWhatsAppAccount, not by this component).
     */
    public string $access_token = '';
    public string $verify_token = '';
    public string $app_secret = '';

    public bool $has_account = false;
    public bool $has_access_token = false;
    public bool $has_verify_token = false;
    public bool $has_app_secret = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->hasRole('owner'), 403);

        $account = Auth::user()->restaurant->whatsAppAccounts()->first();

        if (! $account) {
            return;
        }

        $this->has_account = true;
        $this->phone_number_id = $account->phone_number_id;
        $this->business_account_id = (string) $account->business_account_id;
        $this->display_phone_number = (string) $account->display_phone_number;
        $this->is_active = $account->is_active;
        $this->has_access_token = filled($account->access_token);
        $this->has_verify_token = filled($account->verify_token);
        $this->has_app_secret = filled($account->app_secret);
    }

    /**
     * Re-authorizes at call time rather than trusting mount() - a
     * long-lived page session must not let a since-demoted user keep
     * saving. The account to write to is always re-resolved from the
     * authenticated user's own restaurant - there is no route/request
     * parameter identifying an account at all, so there is no
     * cross-tenant id to forge in the first place.
     */
    public function save(): void
    {
        abort_unless(Auth::user()->hasRole('owner'), 403);

        $restaurant = Auth::user()->restaurant;
        $existing = $restaurant->whatsAppAccounts()->first();

        $validated = $this->validate([
            'phone_number_id' => [
                'required', 'string', 'max:255',
                Rule::unique('whatsapp_accounts', 'phone_number_id')->ignore($existing?->id),
            ],
            'business_account_id' => ['nullable', 'string', 'max:255'],
            'display_phone_number' => ['nullable', 'string', 'max:255'],
            // access_token is NOT NULL at the database level - a brand
            // new account must be given one; an existing account may
            // leave it blank to keep the current token.
            'access_token' => [$existing ? 'nullable' : 'required', 'string', 'max:2000'],
            'verify_token' => ['nullable', 'string', 'max:255'],
            'app_secret' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $account = app(ConfigureWhatsAppAccount::class)->handle(
            $restaurant,
            $existing ?? new WhatsAppAccount(),
            [
                'phone_number_id' => $validated['phone_number_id'],
                'business_account_id' => $validated['business_account_id'] ?: null,
                'display_phone_number' => $validated['display_phone_number'] ?: null,
                'access_token' => $validated['access_token'] ?: null,
                'verify_token' => $validated['verify_token'] ?: null,
                'app_secret' => $validated['app_secret'] ?: null,
                'is_active' => $this->is_active,
            ]
        );

        // Never redisplay a secret after saving - clear the write-only
        // inputs and refresh only the safe "configured" indicators from
        // the freshly saved row.
        $fresh = $account->fresh();
        $this->reset(['access_token', 'verify_token', 'app_secret']);
        $this->has_account = true;
        $this->has_access_token = filled($fresh->access_token);
        $this->has_verify_token = filled($fresh->verify_token);
        $this->has_app_secret = filled($fresh->app_secret);

        session()->flash('status', __('WhatsApp account saved.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="WhatsApp" subheading="Configure the WhatsApp Cloud API account used by your restaurant's inbox">
        @if (session('status'))
            <p class="mb-4 text-sm font-medium text-green-600">{{ session('status') }}</p>
        @endif

        <div class="mb-6 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Status') }}</flux:subheading>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                @if (! $has_account)
                    <flux:badge size="sm" color="zinc">{{ __('Not configured') }}</flux:badge>
                @elseif ($is_active)
                    <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                @endif

                <flux:badge size="sm" :color="$has_access_token ? 'green' : 'zinc'">
                    {{ $has_access_token ? __('Access token configured') : __('Access token not configured') }}
                </flux:badge>
                <flux:badge size="sm" :color="$has_verify_token ? 'green' : 'zinc'">
                    {{ $has_verify_token ? __('Verify token configured') : __('Verify token not configured') }}
                </flux:badge>
                <flux:badge size="sm" :color="$has_app_secret ? 'green' : 'zinc'">
                    {{ $has_app_secret ? __('App secret configured') : __('App secret not configured') }}
                </flux:badge>
            </div>

            @unless ($is_active)
                <p class="mt-3 text-sm text-zinc-500">
                    {{ __('An inactive account is ignored by both incoming webhooks and outgoing messages.') }}
                </p>
            @endunless
        </div>

        <form wire:submit="save" class="flex flex-col gap-6">
            <div>
                <flux:subheading>{{ __('Public configuration') }}</flux:subheading>

                <div class="mt-3 flex flex-col gap-4">
                    <flux:input wire:model="display_phone_number" label="{{ __('Display phone number') }}" />
                    <flux:input wire:model="phone_number_id" label="{{ __('Phone number ID') }}" required />
                    <flux:input wire:model="business_account_id" label="{{ __('Business account ID') }}" />

                    <flux:switch wire:model="is_active" label="{{ __('Active') }}" />
                </div>
            </div>

            <div class="border-t border-neutral-200 pt-4 dark:border-neutral-700">
                <flux:subheading>{{ __('Secrets') }}</flux:subheading>
                <p class="text-xs text-zinc-500">
                    {{ __('For security, saved secrets are never displayed. Leave a field blank to keep its current value.') }}
                </p>

                <div class="mt-3 flex flex-col gap-4">
                    <flux:input
                        wire:model="access_token"
                        type="password"
                        label="{{ __('Access token') }}"
                        :placeholder="$has_access_token ? __('Configured - leave blank to keep') : __('Not configured')"
                        :required="! $has_account"
                    />
                    <flux:input
                        wire:model="verify_token"
                        type="password"
                        label="{{ __('Verify token') }}"
                        :placeholder="$has_verify_token ? __('Configured - leave blank to keep') : __('Generated automatically if left blank')"
                    />
                    <flux:input
                        wire:model="app_secret"
                        type="password"
                        label="{{ __('App secret') }}"
                        :placeholder="$has_app_secret ? __('Configured - leave blank to keep') : __('Not configured')"
                    />
                </div>
            </div>

            <div>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </x-settings.layout>
</section>
