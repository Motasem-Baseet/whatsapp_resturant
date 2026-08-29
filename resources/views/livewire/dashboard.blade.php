<?php

use App\Enums\ConversationStatus;
use App\Models\Order;
use App\Services\Dashboard\GetDashboardMetrics;
use App\Services\Onboarding\GetOnboardingProgress;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * A single shared landing page for every authenticated, verified user
 * (the route itself carries no role middleware - see routes/web.php),
 * branching internally by role rather than being split into separate
 * routes the way Orders/Kitchen are. Reuses the existing
 * OrderPolicy abilities (viewAny = owner|cashier, viewAnyAsKitchen =
 * kitchen) to decide which slice of data to fetch and render, instead
 * of introducing a new DashboardPolicy - a roleless user (or one with
 * no restaurant) matches neither branch and gets an empty, data-free
 * state.
 */
new #[Layout('components.layouts.app')] class extends Component {
    #[Computed]
    public function isOperationalUser(): bool
    {
        return Auth::user()->can('viewAny', Order::class);
    }

    #[Computed]
    public function isKitchenUser(): bool
    {
        return Auth::user()->can('viewAnyAsKitchen', Order::class);
    }

    /**
     * Null for a user who is neither owner/cashier nor kitchen, or who
     * has no restaurant at all - no restaurant-scoped query ever runs
     * in that case.
     */
    #[Computed]
    public function metrics(): ?array
    {
        $user = Auth::user();
        $restaurant = $user->restaurant;

        if (! $restaurant) {
            return null;
        }

        if ($this->isOperationalUser) {
            return app(GetDashboardMetrics::class)->forOwnerOrCashier($restaurant, $user);
        }

        if ($this->isKitchenUser) {
            return app(GetDashboardMetrics::class)->forKitchen($restaurant);
        }

        return null;
    }

    /**
     * Null for anyone but an owner whose own restaurant has not yet
     * completed onboarding - this is deliberately a separate, isolated
     * computed property rather than folded into metrics() above, so
     * the setup reminder can never interfere with (or be mistaken for)
     * an actual financial/operational metric. Cashier and kitchen never
     * see this, matching onboarding's owner-only access rule elsewhere.
     */
    #[Computed]
    public function onboardingProgress(): ?array
    {
        $user = Auth::user();

        if (! $user->hasRole('owner') || ! $user->restaurant || $user->restaurant->onboarding_completed_at !== null) {
            return null;
        }

        return app(GetOnboardingProgress::class)->handle($user->restaurant);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>

    @if ($this->isOperationalUser && $this->metrics)
        <flux:subheading>{{ __('Today for :restaurant', ['restaurant' => Auth::user()->restaurant->name]) }}</flux:subheading>

        @if ($this->onboardingProgress)
            <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/20">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="font-medium">{{ __('Finish setting up your restaurant') }}</p>
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">
                            {{ __(':completed of :total steps complete', ['completed' => $this->onboardingProgress['completed_steps'], 'total' => $this->onboardingProgress['total_steps']]) }}
                        </p>
                    </div>
                    <flux:button :href="route('onboarding.show')" size="sm" variant="primary" wire:navigate>
                        {{ __('Continue setup') }}
                    </flux:button>
                </div>
            </div>
        @endif

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:subheading>{{ __("Today's orders") }}</flux:subheading>
                <p class="mt-1 text-2xl font-semibold">{{ $this->metrics['todays_orders'] }}</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:subheading>{{ __("Today's revenue") }}</flux:subheading>
                <p class="mt-1 text-2xl font-semibold">{{ number_format((float) $this->metrics['todays_revenue'], 2) }}</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:subheading>{{ __('Pending orders') }}</flux:subheading>
                <p class="mt-1 text-2xl font-semibold">{{ $this->metrics['pending_orders'] }}</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:subheading>{{ __('Active kitchen orders') }}</flux:subheading>
                <p class="mt-1 text-2xl font-semibold">{{ $this->metrics['active_kitchen_orders'] }}</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:subheading>{{ __('Orders needing attention') }}</flux:subheading>
                <p class="mt-1 text-2xl font-semibold {{ $this->metrics['attention_orders_count'] > 0 ? 'text-red-600' : '' }}">
                    {{ $this->metrics['attention_orders_count'] }}
                </p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:subheading>{{ __("Today's new customers") }}</flux:subheading>
                <p class="mt-1 text-2xl font-semibold">{{ $this->metrics['todays_new_customers'] }}</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:subheading>{{ __('My unread conversations') }}</flux:subheading>
                <p class="mt-1 text-2xl font-semibold">{{ $this->metrics['unread_conversations'] }}</p>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <div class="flex items-center justify-between">
                    <flux:subheading>{{ __('Recent orders') }}</flux:subheading>
                    <flux:button :href="route('orders.index')" size="sm" variant="ghost" wire:navigate>{{ __('View all') }}</flux:button>
                </div>

                <div class="mt-3 flex flex-col gap-3">
                    @forelse ($this->metrics['recent_orders'] as $order)
                        <div class="flex items-center justify-between text-sm" wire:key="dash-order-{{ $order->id }}">
                            <div>
                                <a href="{{ route('orders.show', $order) }}" wire:navigate class="font-medium hover:underline">
                                    {{ __('Order #:id', ['id' => $order->id]) }}
                                </a>
                                <span class="text-zinc-500">&middot; {{ $order->customer->name }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:badge size="sm">{{ $order->status->label() }}</flux:badge>
                                <span>{{ number_format($order->total, 2) }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">{{ __('No orders yet.') }}</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <div class="flex items-center justify-between">
                    <flux:subheading>{{ __('Recent conversations') }}</flux:subheading>
                    <flux:button :href="route('inbox.index')" size="sm" variant="ghost" wire:navigate>{{ __('View all') }}</flux:button>
                </div>

                <div class="mt-3 flex flex-col gap-3">
                    @forelse ($this->metrics['recent_conversations'] as $conversation)
                        <div class="flex items-center justify-between text-sm" wire:key="dash-conv-{{ $conversation->id }}">
                            <div class="flex items-center gap-2">
                                @if (in_array($conversation->id, $this->metrics['unread_conversation_ids'], true))
                                    <span class="inline-block h-2 w-2 rounded-full bg-blue-500" title="{{ __('Unread') }}"></span>
                                @endif
                                <a href="{{ route('conversations.show', $conversation) }}" wire:navigate class="font-medium hover:underline">
                                    {{ $conversation->customer->name }}
                                </a>
                                <span class="text-zinc-500">{{ $conversation->assignedUser?->name ?? __('Unassigned') }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-zinc-500">
                                @if ($conversation->status === ConversationStatus::Open)
                                    <flux:badge size="sm" color="green">{{ __('Open') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Closed') }}</flux:badge>
                                @endif
                                <span>{{ $conversation->last_message_at?->diffForHumans() ?? __('No messages yet') }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">{{ __('No conversations yet.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    @elseif ($this->isKitchenUser && $this->metrics)
        <flux:subheading>{{ __('Kitchen overview for :restaurant', ['restaurant' => Auth::user()->restaurant->name]) }}</flux:subheading>

        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:subheading>{{ __('Confirmed') }}</flux:subheading>
                <p class="mt-1 text-2xl font-semibold">{{ $this->metrics['confirmed_count'] }}</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:subheading>{{ __('Preparing') }}</flux:subheading>
                <p class="mt-1 text-2xl font-semibold">{{ $this->metrics['preparing_count'] }}</p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:subheading>{{ __('Ready') }}</flux:subheading>
                <p class="mt-1 text-2xl font-semibold">{{ $this->metrics['ready_count'] }}</p>
            </div>
        </div>

        <div class="mt-6 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Needing attention') }}</flux:subheading>
            <p class="mt-1 text-2xl font-semibold {{ $this->metrics['attention_orders_count'] > 0 ? 'text-red-600' : '' }}">
                {{ $this->metrics['attention_orders_count'] }}
            </p>
        </div>

        <div class="mt-6 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="flex items-center justify-between">
                <flux:subheading>{{ __('Recent kitchen orders') }}</flux:subheading>
                <flux:button :href="route('kitchen.orders.index')" size="sm" variant="ghost" wire:navigate>{{ __('View all') }}</flux:button>
            </div>

            <div class="mt-3 flex flex-col gap-3">
                @forelse ($this->metrics['recent_orders'] as $order)
                    <div class="flex items-center justify-between text-sm" wire:key="dash-kitchen-order-{{ $order->id }}">
                        <a href="{{ route('kitchen.orders.show', $order) }}" wire:navigate class="font-medium hover:underline">
                            {{ __('Order #:id', ['id' => $order->id]) }}
                        </a>
                        <flux:badge size="sm">{{ $order->status->label() }}</flux:badge>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">{{ __('No active orders right now.') }}</p>
                @endforelse
            </div>
        </div>
    @else
        <flux:subheading>{{ __('Welcome back.') }}</flux:subheading>
    @endif
</section>
