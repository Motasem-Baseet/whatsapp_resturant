<?php

use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewAny', Order::class);
    }

    #[Computed]
    public function orders()
    {
        return Auth::user()->restaurant
            ->orders()
            ->with('customer')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Used only to address this user's own restaurant's broadcast
     * channel below, matching inbox/index.blade.php's own
     * restaurantId()/channel-authorization reasoning.
     */
    #[Computed]
    public function restaurantId(): int
    {
        return Auth::user()->restaurant_id;
    }

    /**
     * Fired on every successful status transition via
     * OrderStatusUpdated, from either the owner/cashier or kitchen
     * entry point. Nothing from the payload is trusted; the empty body
     * just triggers Livewire to re-render, re-running the tenant-scoped
     * orders() query above fresh from the database.
     */
    #[On('echo-private:restaurants.{restaurantId}.orders,.order.status-updated')]
    public function onOrderStatusUpdated(): void
    {
        //
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Orders') }}</flux:heading>
            <flux:subheading>{{ __('Orders placed for your restaurant.') }}</flux:subheading>
        </div>

        <flux:button :href="route('orders.create')" variant="primary" wire:navigate>
            {{ __('New order') }}
        </flux:button>
    </div>

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Order') }}</flux:table.column>
            <flux:table.column>{{ __('Customer') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Total') }}</flux:table.column>
            <flux:table.column>{{ __('Created') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->orders as $order)
                <flux:table.row wire:key="order-{{ $order->id }}">
                    <flux:table.cell>#{{ $order->id }}</flux:table.cell>
                    <flux:table.cell>{{ $order->customer->name }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm">{{ $order->status->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ number_format($order->total, 2) }}</flux:table.cell>
                    <flux:table.cell>{{ $order->created_at->format('M j, Y g:i A') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:button :href="route('orders.show', $order)" size="sm" wire:navigate>
                            {{ __('View') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center text-zinc-500">
                        {{ __('No orders yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
