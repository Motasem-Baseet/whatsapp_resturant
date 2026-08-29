<?php

use App\Models\Product;
use App\Services\Menu\CreateProduct;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $category_id = '';
    public string $name = '';
    public string $description = '';
    public string $price = '';

    public function mount(): void
    {
        $this->authorize('create', Product::class);
    }

    /**
     * Only active categories belonging to the current restaurant may be
     * selected when creating a new product.
     */
    #[Computed]
    public function categories()
    {
        return Auth::user()->restaurant
            ->categories()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a product for the current owner's own restaurant.
     * restaurant_id is never accepted here - it is assigned server-side
     * from the current tenant. category_id is validated to both belong
     * to the current restaurant and be active before it ever reaches
     * the database; the products table's composite foreign key is the
     * final backstop even if that validation were ever bypassed.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')
                    ->where('restaurant_id', Auth::user()->restaurant_id)
                    ->where('is_active', true),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'gt:0'],
        ]);

        app(CreateProduct::class)->handle(Auth::user()->restaurant, $validated);

        session()->flash('status', __('Product created.'));

        $this->redirect(route('menu.products.index'), navigate: true);
    }
}; ?>

<section class="w-full">
    @if (Auth::user()->restaurant?->onboarding_completed_at === null)
        <flux:button :href="route('onboarding.show')" size="sm" variant="ghost" wire:navigate>
            {{ __('← Back to setup') }}
        </flux:button>
    @endif

    <flux:heading size="xl">{{ __('Add product') }}</flux:heading>
    <flux:subheading>{{ __('Create a new item on your menu.') }}</flux:subheading>

    <form wire:submit="save" class="mt-6 flex flex-col gap-6">
        <flux:select wire:model="category_id" label="{{ __('Category') }}" placeholder="{{ __('Select a category') }}">
            @foreach ($this->categories as $category)
                <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="name" label="{{ __('Name') }}" type="text" required autofocus />

        <flux:textarea wire:model="description" label="{{ __('Description') }}" />

        <flux:input wire:model="price" label="{{ __('Price') }}" type="number" step="0.01" min="0.01" required />

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">{{ __('Create product') }}</flux:button>
            <flux:button :href="route('menu.products.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
