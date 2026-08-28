<?php

use App\Services\Restaurants\UpdateRestaurant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Owner-only settings page for the restaurant's own profile fields.
 *
 * There is deliberately no restaurant/restaurant_id property of any
 * kind - every action re-resolves Auth::user()->restaurant fresh, the
 * same "don't trust component state, don't trust the client" approach
 * already used by the WhatsApp settings page (Phase 14). Since there is
 * no route parameter identifying a restaurant either, there is no input
 * anywhere in this page that could redirect an update to a different
 * restaurant.
 *
 * logo_path is treated as a plain URL field, not a file upload: this
 * codebase has no existing attachment/image-upload infrastructure
 * anywhere (no WithFileUploads usage, no polymorphic attachment model,
 * no configured upload disk in use) to reuse, and introducing one from
 * scratch for a single optional field would be disproportionate - see
 * the Phase 16 report for the full reasoning.
 */
new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';
    public string $phone = '';
    public string $address = '';
    public string $logo_path = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->hasRole('owner'), 403);

        $restaurant = Auth::user()->restaurant;

        $this->name = $restaurant->name;
        $this->phone = $restaurant->phone;
        $this->address = $restaurant->address;
        $this->logo_path = (string) $restaurant->logo_path;
    }

    /**
     * Re-authorizes at call time rather than trusting mount() - a
     * long-lived page session must not let a since-demoted user keep
     * saving. The restaurant to update is always re-resolved from the
     * authenticated user's own relationship.
     */
    public function save(): void
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

        session()->flash('status', __('Restaurant settings saved.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Restaurant" subheading="Update your restaurant's profile information">
        @if (session('status'))
            <p class="mb-4 text-sm font-medium text-green-600">{{ session('status') }}</p>
        @endif

        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:input wire:model="name" label="{{ __('Name') }}" required />
            <flux:input wire:model="phone" label="{{ __('Phone') }}" required />
            <flux:input wire:model="address" label="{{ __('Address') }}" required />
            <flux:input wire:model="logo_path" label="{{ __('Logo URL') }}" placeholder="https://…" />

            <div>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </x-settings.layout>
</section>
