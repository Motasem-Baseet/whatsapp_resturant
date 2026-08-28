<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewAnyAsKitchen', Order::class);
    }

    /**
     * Orders relevant to the kitchen workflow only: confirmed,
     * preparing, ready - never pending, completed, or cancelled.
     *
     * The restaurant relationship is the tenant boundary (matching the
     * pattern used by every other listing page), then the query is
     * narrowed to kitchen-relevant statuses. Ordered with preparing
     * first (already in progress), then confirmed, then ready;
     * created_at ascending within each status - relying on PHP 8's
     * stable sort to preserve that order when sorting by priority
     * afterwards, since neither MySQL's FIELD() nor an equivalent is
     * portable to the SQLite connection the test suite uses.
     */
    #[Computed]
    public function orders()
    {
        $priority = [
            OrderStatus::Preparing->value => 0,
            OrderStatus::Confirmed->value => 1,
            OrderStatus::Ready->value => 2,
        ];

        return Auth::user()->restaurant
            ->orders()
            ->with(['customer', 'items', 'conversation'])
            ->whereIn('status', [
                OrderStatus::Confirmed->value,
                OrderStatus::Preparing->value,
                OrderStatus::Ready->value,
            ])
            ->orderBy('created_at')
            ->get()
            ->sortBy(fn ($order) => $priority[$order->status->value])
            ->values();
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
     * Fired on every successful status transition, from either entry
     * point - most valuable here, since a transition can make an order
     * appear in or disappear from this status-filtered list entirely
     * (e.g. confirmed -> preparing, or preparing -> ready leaving the
     * kitchen's active queue once completed). Nothing from the payload
     * is trusted; the empty body just triggers a re-render, re-running
     * the tenant- and status-scoped orders() query above fresh from the
     * database.
     */
    #[On('echo-private:restaurants.{restaurantId}.orders,.order.status-updated')]
    public function onOrderStatusUpdated(): void
    {
        //
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Kitchen') }}</flux:heading>
    <flux:subheading>{{ __('Orders currently in progress for your restaurant.') }}</flux:subheading>

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Order') }}</flux:table.column>
            <flux:table.column>{{ __('Customer') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Items') }}</flux:table.column>
            <flux:table.column>{{ __('Total') }}</flux:table.column>
            <flux:table.column>{{ __('Conversation') }}</flux:table.column>
            <flux:table.column>{{ __('Created') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->orders as $order)
                <flux:table.row wire:key="kitchen-order-{{ $order->id }}">
                    <flux:table.cell>#{{ $order->id }}</flux:table.cell>
                    <flux:table.cell>{{ $order->customer->name }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm">{{ $order->status->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ __(':count items', ['count' => $order->items->count()]) }}</flux:table.cell>
                    <flux:table.cell>{{ number_format($order->total, 2) }}</flux:table.cell>
                    <flux:table.cell>{{ $order->conversation ? __('Yes') : __('—') }}</flux:table.cell>
                    <flux:table.cell>{{ $order->created_at->format('M j, Y g:i A') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:button :href="route('kitchen.orders.show', $order)" size="sm" wire:navigate>
                            {{ __('View') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" class="text-center text-zinc-500">
                        {{ __('No active orders right now.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
