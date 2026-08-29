<?php

use App\Services\Menu\CreateCategory;
use App\Services\Onboarding\GetOnboardingProgress;
use App\Services\Restaurants\UpdateRestaurant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Guided setup checklist for a newly registered restaurant (Phase 26).
 * Deliberately a single page of independent sections rather than a
 * stateful multi-route wizard - there is no "current step" to persist
 * or get stuck on, an already-satisfied section is simply shown as
 * done immediately, and there is nothing here that could produce a
 * redirect loop or trap the owner.
 *
 * Every mutation (saveProfile/createCategory/complete) re-authorizes
 * for itself rather than trusting mount() - a long-lived page session
 * must not let a since-demoted user keep acting, matching every other
 * owner-only settings page in this app.
 */
new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';
    public string $phone = '';
    public string $address = '';
    public string $logo_path = '';

    public string $category_name = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->hasRole('owner'), 403);

        $restaurant = Auth::user()->restaurant;

        $this->name = $restaurant->name;
        $this->phone = $restaurant->phone;
        $this->address = $restaurant->address;
        $this->logo_path = (string) $restaurant->logo_path;
    }

    #[Computed]
    public function progress(): array
    {
        return app(GetOnboardingProgress::class)->handle(Auth::user()->restaurant);
    }

    #[Computed]
    public function categories()
    {
        return Auth::user()->restaurant->categories()->orderBy('name')->get();
    }

    #[Computed]
    public function products()
    {
        return Auth::user()->restaurant->products()->orderBy('name')->limit(10)->get();
    }

    /**
     * Only the presence/active-state of an account, never any secret
     * field - the same safe-status convention settings.whatsapp already
     * establishes.
     */
    #[Computed]
    public function whatsAppAccount()
    {
        return Auth::user()->restaurant->whatsAppAccounts()->first();
    }

    public function saveProfile(): void
    {
        abort_unless(Auth::user()->hasRole('owner'), 403);

        $restaurant = Auth::user()->restaurant;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'logo_path' => ['nullable', 'url', 'max:2048'],
        ]);

        app(UpdateRestaurant::class)->handle($restaurant, [
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'address' => $validated['address'],
            'logo_path' => $validated['logo_path'] ?: null,
        ]);

        $fresh = $restaurant->fresh();
        $this->name = $fresh->name;
        $this->phone = $fresh->phone;
        $this->address = $fresh->address;
        $this->logo_path = (string) $fresh->logo_path;

        unset($this->progress);

        session()->flash('status', __('Restaurant profile saved.'));
    }

    /**
     * Creates a category for the current owner's own restaurant, exactly
     * like menu.categories.create.save() - restaurant_id is never
     * accepted here, and a freshly created category is active by
     * default (categories.is_active defaults to true at the database
     * level), which is exactly what this step requires.
     */
    public function createCategory(): void
    {
        abort_unless(Auth::user()->hasRole('owner'), 403);

        $validated = $this->validate([
            'category_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')->where('restaurant_id', Auth::user()->restaurant_id),
            ],
        ]);

        app(CreateCategory::class)->handle(Auth::user()->restaurant, ['name' => $validated['category_name']]);

        $this->reset('category_name');
        unset($this->categories, $this->progress);

        session()->flash('status', __('Category created.'));
    }

    /**
     * Marks onboarding complete. Progress is re-derived fresh from the
     * database here - never from the (possibly stale, and in any case
     * client-influenced-only-by-navigation) cached computed property -
     * so a forged request calling this directly can never mark
     * onboarding complete while a requirement is actually missing.
     */
    public function complete(): void
    {
        abort_unless(Auth::user()->hasRole('owner'), 403);

        $restaurant = Auth::user()->restaurant;
        $progress = app(GetOnboardingProgress::class)->handle($restaurant);

        if (! $progress['all_complete']) {
            $this->addError('completion', __('Please complete every step before finishing setup.'));

            return;
        }

        $restaurant->onboarding_completed_at = now();
        $restaurant->save();

        $this->redirect(route('dashboard'), navigate: true);
    }
}; ?>

<section class="w-full max-w-3xl">
    <flux:heading size="xl">{{ __('Set up your restaurant') }}</flux:heading>
    <flux:subheading>{{ __('A few quick steps before your restaurant is ready to go.') }}</flux:subheading>

    @if (session('status'))
        <p class="mt-4 text-sm font-medium text-green-600">{{ session('status') }}</p>
    @endif

    <div class="mt-6 flex flex-col gap-6">
        {{-- Step 1: Restaurant profile --}}
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="flex items-center justify-between">
                <flux:subheading>{{ __('1. Restaurant profile') }}</flux:subheading>
                @if ($this->progress['steps']['profile'])
                    <flux:badge size="sm" color="green">{{ __('Complete') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="zinc">{{ __('Incomplete') }}</flux:badge>
                @endif
            </div>

            <form wire:submit="saveProfile" class="mt-4 flex flex-col gap-4">
                <flux:input wire:model="name" label="{{ __('Name') }}" required />
                <flux:input wire:model="phone" label="{{ __('Phone') }}" required />
                <flux:input wire:model="address" label="{{ __('Address') }}" required />
                <flux:input wire:model="logo_path" label="{{ __('Logo URL') }}" placeholder="https://…" />

                <div>
                    <flux:button type="submit" size="sm" variant="primary">{{ __('Save profile') }}</flux:button>
                </div>
            </form>
        </div>

        {{-- Step 2: Categories --}}
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="flex items-center justify-between">
                <flux:subheading>{{ __('2. Menu categories') }}</flux:subheading>
                @if ($this->progress['steps']['category'])
                    <flux:badge size="sm" color="green">{{ __('Complete') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="zinc">{{ __('Incomplete') }}</flux:badge>
                @endif
            </div>

            @if ($this->categories->isNotEmpty())
                <ul class="mt-3 flex flex-wrap gap-2">
                    @foreach ($this->categories as $category)
                        <flux:badge size="sm" :color="$category->is_active ? 'zinc' : 'red'" wire:key="onboarding-category-{{ $category->id }}">
                            {{ $category->name }}
                        </flux:badge>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 text-sm text-zinc-500">{{ __('No categories yet.') }}</p>
            @endif

            <form wire:submit="createCategory" class="mt-4 flex items-end gap-3">
                <flux:input wire:model="category_name" label="{{ __('New category name') }}" class="max-w-xs" />
                <flux:button type="submit" size="sm" variant="primary">{{ __('Add category') }}</flux:button>
            </form>
        </div>

        {{-- Step 3: Products --}}
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="flex items-center justify-between">
                <flux:subheading>{{ __('3. Products') }}</flux:subheading>
                @if ($this->progress['steps']['product'])
                    <flux:badge size="sm" color="green">{{ __('Complete') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="zinc">{{ __('Incomplete') }}</flux:badge>
                @endif
            </div>

            @if ($this->products->isNotEmpty())
                <ul class="mt-3 flex flex-wrap gap-2">
                    @foreach ($this->products as $product)
                        <flux:badge size="sm" :color="$product->is_active ? 'zinc' : 'red'" wire:key="onboarding-product-{{ $product->id }}">
                            {{ $product->name }}
                        </flux:badge>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 text-sm text-zinc-500">{{ __('No products yet.') }}</p>
            @endif

            <div class="mt-4">
                <flux:button :href="route('menu.products.create')" size="sm" variant="primary" wire:navigate>
                    {{ __('Add a product') }}
                </flux:button>
            </div>
        </div>

        {{-- Step 4: WhatsApp --}}
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="flex items-center justify-between">
                <flux:subheading>{{ __('4. WhatsApp') }}</flux:subheading>
                @if ($this->progress['steps']['whatsapp'])
                    <flux:badge size="sm" color="green">{{ __('Complete') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="zinc">{{ __('Incomplete') }}</flux:badge>
                @endif
            </div>

            <p class="mt-3 text-sm text-zinc-500">
                @if ($this->whatsAppAccount)
                    {{ $this->whatsAppAccount->is_active ? __('A WhatsApp account is configured and active.') : __('A WhatsApp account is configured but inactive.') }}
                @else
                    {{ __('No WhatsApp account configured yet.') }}
                @endif
            </p>

            <div class="mt-4">
                <flux:button :href="route('settings.whatsapp')" size="sm" variant="primary" wire:navigate>
                    {{ __('Configure WhatsApp') }}
                </flux:button>
            </div>
        </div>

        {{-- Step 5: Review & complete --}}
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('5. Review & complete') }}</flux:subheading>

            <ul class="mt-3 flex flex-col gap-1 text-sm">
                <li>{{ $this->progress['steps']['profile'] ? '✓' : '✗' }} {{ __('Restaurant profile complete') }}</li>
                <li>{{ $this->progress['steps']['category'] ? '✓' : '✗' }} {{ __('At least one active category') }}</li>
                <li>{{ $this->progress['steps']['product'] ? '✓' : '✗' }} {{ __('At least one active product') }}</li>
                <li>{{ $this->progress['steps']['whatsapp'] ? '✓' : '✗' }} {{ __('WhatsApp configured') }}</li>
            </ul>

            @error('completion') <p class="mt-3 text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="mt-4">
                <flux:button
                    wire:click="complete"
                    variant="primary"
                    :disabled="! $this->progress['all_complete']"
                >
                    {{ __('Finish setup (:completed/:total)', ['completed' => $this->progress['completed_steps'], 'total' => $this->progress['total_steps']]) }}
                </flux:button>
            </div>
        </div>
    </div>
</section>
