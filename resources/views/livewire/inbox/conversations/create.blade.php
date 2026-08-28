<?php

use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Inbox\CreateConversation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $customer_id = '';

    public function mount(): void
    {
        $this->authorize('create', Conversation::class);
    }

    #[Computed]
    public function customers()
    {
        return Auth::user()->restaurant->customers()->orderBy('name')->get();
    }

    /**
     * Start a conversation for an existing customer belonging to the
     * current restaurant. restaurant_id is never accepted here - it is
     * assigned server-side from the current owner/cashier's own
     * restaurant, and the selected customer is validated to belong to
     * that same restaurant before the service is ever called.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')->where('restaurant_id', Auth::user()->restaurant_id),
            ],
        ]);

        $customer = Auth::user()->restaurant->customers()->findOrFail($validated['customer_id']);

        $conversation = app(CreateConversation::class)->handle(Auth::user()->restaurant, $customer);

        $this->redirect(route('conversations.show', $conversation), navigate: true);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('New conversation') }}</flux:heading>
    <flux:subheading>{{ __('Start a conversation with an existing customer.') }}</flux:subheading>

    <form wire:submit="save" class="mt-6 flex flex-col gap-6">
        <flux:select wire:model="customer_id" label="{{ __('Customer') }}" placeholder="{{ __('Select a customer') }}">
            @foreach ($this->customers as $customer)
                <flux:select.option value="{{ $customer->id }}">{{ $customer->name }} ({{ $customer->phone }})</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">{{ __('Start conversation') }}</flux:button>
            <flux:button :href="route('inbox.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
