<?php

use App\Enums\ConversationStatus;
use App\Enums\OrderStatus;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Read-only customer profile: information, statistics, and limited
 * order/conversation history. Purely a composition of the existing
 * Customer/Order/Conversation domains - every list below reuses the
 * existing relationships and the existing per-user unread scope
 * (Conversation::scopeUnreadFor(), from Phase 11); nothing new is
 * calculated or stored.
 */
new #[Layout('components.layouts.app')] class extends Component {
    public Customer $customer;

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);

        $this->customer = $customer;
    }

    /**
     * Every value here is a single SQL aggregate against this
     * customer's own orders()/conversations() relationship - never a
     * full collection pulled into PHP just to count/sum it.
     *
     * total_spent excludes cancelled orders, matching the exact same
     * rule already established by the dashboard's revenue metric
     * (GetDashboardMetrics::forOwnerOrCashier()) - a cancelled order was
     * never actually paid for.
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'total_orders' => $this->customer->orders()->count(),
            'completed_orders' => $this->customer->orders()->where('status', OrderStatus::Completed->value)->count(),
            'total_spent' => number_format(
                (float) $this->customer->orders()->where('status', '!=', OrderStatus::Cancelled->value)->sum('total'),
                2, '.', ''
            ),
            'latest_order_at' => $this->toCarbon($this->customer->orders()->max('created_at')),
            'conversation_count' => $this->customer->conversations()->count(),
            'latest_conversation_at' => $this->toCarbon($this->customer->conversations()->max('last_message_at')),
        ];
    }

    #[Computed]
    public function orders()
    {
        return $this->customer->orders()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function conversations()
    {
        return $this->customer->conversations()
            ->with('assignedUser')
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    /**
     * IDs of this customer's conversations unread for the current user -
     * reuses Conversation::scopeUnreadFor() exactly as the inbox list
     * and dashboard already do, rather than any new read/unread logic.
     */
    #[Computed]
    public function unreadConversationIds(): array
    {
        return $this->customer->conversations()
            ->unreadFor(Auth::user())
            ->pluck('conversations.id')
            ->all();
    }

    /**
     * max()/sum() are raw aggregates that bypass Eloquent's datetime
     * casting, so a max('created_at') comes back as a plain string (or
     * null) rather than a Carbon instance.
     */
    protected function toCarbon(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ $customer->name }}</flux:heading>
            <flux:subheading>{{ $customer->phone }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            @can('update', $customer)
                <flux:button :href="route('customers.edit', $customer)" size="sm" wire:navigate>
                    {{ __('Edit') }}
                </flux:button>
            @endcan
            <flux:button :href="route('customers.index')" variant="ghost" wire:navigate>
                {{ __('Back to customers') }}
            </flux:button>
        </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Total orders') }}</flux:subheading>
            <p class="mt-1 text-2xl font-semibold">{{ $this->stats['total_orders'] }}</p>
        </div>
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Completed orders') }}</flux:subheading>
            <p class="mt-1 text-2xl font-semibold">{{ $this->stats['completed_orders'] }}</p>
        </div>
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Total spent') }}</flux:subheading>
            <p class="mt-1 text-2xl font-semibold">{{ number_format((float) $this->stats['total_spent'], 2) }}</p>
        </div>
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Conversations') }}</flux:subheading>
            <p class="mt-1 text-2xl font-semibold">{{ $this->stats['conversation_count'] }}</p>
        </div>
    </div>

    <div class="mt-6 grid gap-6 md:grid-cols-3">
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Customer information') }}</flux:subheading>
            <dl class="mt-3 flex flex-col gap-2 text-sm">
                <div>
                    <dt class="text-zinc-500">{{ __('Name') }}</dt>
                    <dd>{{ $customer->name }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Phone') }}</dt>
                    <dd>{{ $customer->phone }}</dd>
                </div>
                @if ($customer->notes)
                    <div>
                        <dt class="text-zinc-500">{{ __('Notes') }}</dt>
                        <dd>{{ $customer->notes }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-zinc-500">{{ __('Customer since') }}</dt>
                    <dd>{{ $customer->created_at->format('M j, Y') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Latest order') }}</dt>
                    <dd>{{ $this->stats['latest_order_at']?->diffForHumans() ?? __('Never') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Latest conversation activity') }}</dt>
                    <dd>{{ $this->stats['latest_conversation_at']?->diffForHumans() ?? __('Never') }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700 md:col-span-2">
            <flux:subheading>{{ __('Order history') }}</flux:subheading>

            <flux:table class="mt-3">
                <flux:table.columns>
                    <flux:table.column>{{ __('Order') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Total') }}</flux:table.column>
                    <flux:table.column>{{ __('Created') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->orders as $order)
                        <flux:table.row wire:key="customer-order-{{ $order->id }}">
                            <flux:table.cell>{{ __('Order #:id', ['id' => $order->id]) }}</flux:table.cell>
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
                            <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                {{ __('No orders yet.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:subheading>{{ __('Conversation history') }}</flux:subheading>

        <flux:table class="mt-3">
            <flux:table.columns>
                <flux:table.column></flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Assigned to') }}</flux:table.column>
                <flux:table.column>{{ __('Last activity') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->conversations as $conversation)
                    <flux:table.row wire:key="customer-conversation-{{ $conversation->id }}">
                        <flux:table.cell>
                            @if (in_array($conversation->id, $this->unreadConversationIds, true))
                                <span class="inline-block h-2 w-2 rounded-full bg-blue-500" title="{{ __('Unread') }}"></span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($conversation->status === ConversationStatus::Open)
                                <flux:badge size="sm" color="green">{{ __('Open') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Closed') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $conversation->assignedUser?->name ?? __('Unassigned') }}</flux:table.cell>
                        <flux:table.cell>{{ $conversation->last_message_at?->diffForHumans() ?? __('No messages yet') }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:button :href="route('conversations.show', $conversation)" size="sm" wire:navigate>
                                {{ __('View') }}
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center text-zinc-500">
                            {{ __('No conversations yet.') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
