<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewAny', Conversation::class);
    }

    #[Computed]
    public function conversations()
    {
        return Auth::user()->restaurant
            ->conversations()
            ->with(['customer', 'assignedUser'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Inbox') }}</flux:heading>
            <flux:subheading>{{ __('Conversations with your customers.') }}</flux:subheading>
        </div>

        <flux:button :href="route('conversations.create')" variant="primary" wire:navigate>
            {{ __('New conversation') }}
        </flux:button>
    </div>

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Customer') }}</flux:table.column>
            <flux:table.column>{{ __('Phone') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Assigned to') }}</flux:table.column>
            <flux:table.column>{{ __('Last message') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->conversations as $conversation)
                <flux:table.row wire:key="conversation-{{ $conversation->id }}">
                    <flux:table.cell>{{ $conversation->customer->name }}</flux:table.cell>
                    <flux:table.cell>{{ $conversation->customer->phone }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($conversation->status === \App\Enums\ConversationStatus::Open)
                            <flux:badge color="green" size="sm">{{ __('Open') }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">{{ __('Closed') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $conversation->assignedUser?->name ?? __('Unassigned') }}</flux:table.cell>
                    <flux:table.cell>{{ $conversation->last_message_at?->diffForHumans() ?? __('No messages yet') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:button :href="route('conversations.show', $conversation)" size="sm" wire:navigate>
                            {{ __('Open') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center text-zinc-500">
                        {{ __('No conversations yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
