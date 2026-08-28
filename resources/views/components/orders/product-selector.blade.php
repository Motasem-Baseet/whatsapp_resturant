{{--
    Shared product search / category filter / quantity-stepper UI for
    both order-creation pages. Renders inline inside the enclosing
    Livewire/Volt component (a plain Blade component, not an isolated
    Livewire child), so every wire:model/wire:click below binds directly
    to the host component's own HasProductSelection trait properties and
    methods - see that trait for the query/validation logic.
--}}
<div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
    <flux:subheading>{{ __('Add products') }}</flux:subheading>

    <div class="mt-3 flex flex-wrap items-end gap-3">
        <div class="min-w-[200px] flex-1">
            <flux:input
                wire:model.live.debounce.300ms="product_search"
                label="{{ __('Search products') }}"
                placeholder="{{ __('Search by name…') }}"
            />
            <div wire:loading wire:target="product_search" class="mt-1 text-xs text-zinc-500">
                {{ __('Searching…') }}
            </div>
        </div>

        <flux:select wire:model.live="category_id" label="{{ __('Category') }}" placeholder="{{ __('All categories') }}" class="w-52">
            <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
            @foreach ($this->availableCategories as $category)
                <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="mt-3 flex items-end gap-3">
        <flux:select wire:model="selected_product_id" label="{{ __('Product') }}" placeholder="{{ __('Select a product') }}" class="flex-1">
            @forelse ($this->availableProducts as $product)
                <flux:select.option value="{{ $product->id }}">{{ $product->name }} ({{ number_format($product->price, 2) }})</flux:select.option>
            @empty
                <flux:select.option value="" disabled>{{ __('No matching products') }}</flux:select.option>
            @endforelse
        </flux:select>

        <flux:input wire:model="selected_quantity" label="{{ __('Qty') }}" type="number" min="1" max="100" class="w-24" />

        <flux:button wire:click="addItem" size="sm">{{ __('Add') }}</flux:button>
    </div>
    @error('selected_product_id') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
    @error('items') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror

    @if (! empty($items))
        <flux:table class="mt-4">
            <flux:table.columns>
                <flux:table.column>{{ __('Product') }}</flux:table.column>
                <flux:table.column>{{ __('Quantity') }}</flux:table.column>
                <flux:table.column>{{ __('Est. line total') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($items as $productId => $item)
                    <flux:table.row wire:key="item-{{ $productId }}">
                        <flux:table.cell>{{ $item['name'] }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:button
                                    wire:click="decrementQuantity({{ $productId }})"
                                    size="sm"
                                    variant="ghost"
                                    :disabled="$item['quantity'] <= 1"
                                >
                                    −
                                </flux:button>
                                <span class="w-6 text-center tabular-nums">{{ $item['quantity'] }}</span>
                                <flux:button wire:click="incrementQuantity({{ $productId }})" size="sm" variant="ghost">
                                    +
                                </flux:button>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>{{ number_format($item['price'] * $item['quantity'], 2) }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button wire:click="removeItem({{ $productId }})" size="sm" variant="ghost">
                                {{ __('Remove') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @else
        <p class="mt-4 text-sm text-zinc-500">{{ __('No products selected yet.') }}</p>
    @endif
</div>
