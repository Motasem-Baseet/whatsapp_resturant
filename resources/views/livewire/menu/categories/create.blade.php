<?php

use App\Models\Category;
use App\Services\Menu\CreateCategory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';

    public function mount(): void
    {
        $this->authorize('create', Category::class);
    }

    /**
     * Create a category for the current owner's own restaurant.
     * restaurant_id is never accepted here - it is assigned server-side
     * from the current tenant by Category's BelongsToRestaurant trait.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')->where('restaurant_id', Auth::user()->restaurant_id),
            ],
        ]);

        app(CreateCategory::class)->handle(Auth::user()->restaurant, $validated);

        session()->flash('status', __('Category created.'));

        $this->redirect(route('menu.categories.index'), navigate: true);
    }
}; ?>

<section class="w-full">
    @if (Auth::user()->restaurant?->onboarding_completed_at === null)
        <flux:button :href="route('onboarding.show')" size="sm" variant="ghost" wire:navigate>
            {{ __('← Back to setup') }}
        </flux:button>
    @endif

    <flux:heading size="xl">{{ __('Add category') }}</flux:heading>
    <flux:subheading>{{ __('Create a new menu category for your restaurant.') }}</flux:subheading>

    <form wire:submit="save" class="mt-6 flex flex-col gap-6">
        <flux:input wire:model="name" label="{{ __('Name') }}" type="text" required autofocus />

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">{{ __('Create category') }}</flux:button>
            <flux:button :href="route('menu.categories.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
