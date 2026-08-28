<?php

use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    /**
     * A plain boolean, not a string filter - there is no forgeable
     * value to allowlist here the way the string filters elsewhere in
     * this codebase need (any value Livewire coerces this to is either
     * truthy or falsy, both entirely safe to branch on below).
     */
    public bool $attentionOnly = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Order::class);
    }

    /**
     * statusHistory is eager-loaded here so Order::requiresAttention()/
     * currentStatusStartedAt() below never issue a query per row - see
     * their own docblocks. The attention filter itself is applied in
     * PHP after fetching (attention is a derived, time-based state, not
     * a column that can be queried directly) rather than as a second
     * database round-trip.
     */
    #[Computed]
    public function orders()
    {
        $orders = Auth::user()->restaurant
            ->orders()
            ->with(['customer', 'statusHistory'])
            ->orderByDesc('created_at')
            ->get();

        if ($this->attentionOnly) {
            $orders = $orders->filter(fn (Order $order) => $order->requiresAttention())->values();
        }

        return $orders;
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

    <div class="mt-6">
        <flux:checkbox wire:model.live="attentionOnly" label="{{ __('Needs attention only') }}" />
    </div>

    <flux:table class="mt-4">
        <flux:table.columns>
            <flux:table.column>{{ __('Order') }}</flux:table.column>
            <flux:table.column>{{ __('Customer') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Attention') }}</flux:table.column>
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
                    <flux:table.cell>
                        @if ($order->requiresAttention())
                            <flux:badge size="sm" color="red">{{ __('Needs attention') }}</flux:badge>
                        @endif
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
                    <flux:table.cell colspan="7" class="text-center text-zinc-500">
                        {{ __('No orders yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
