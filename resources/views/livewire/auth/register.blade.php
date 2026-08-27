<?php

use App\Models\User;
use App\Services\Auth\RegisterRestaurantOwner;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public string $restaurant_name = '';
    public string $restaurant_phone = '';
    public string $restaurant_address = '';

    /**
     * Handle an incoming registration request.
     *
     * Creates the restaurant and its owner user atomically, and signs the
     * new owner in, only once that succeeds.
     */
    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
            'restaurant_name' => ['required', 'string', 'max:255'],
            'restaurant_phone' => ['required', 'string', 'max:30'],
            'restaurant_address' => ['required', 'string', 'max:255'],
        ]);

        $user = app(RegisterRestaurantOwner::class)->handle(
            owner: [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ],
            restaurant: [
                'name' => $validated['restaurant_name'],
                'phone' => $validated['restaurant_phone'],
                'address' => $validated['restaurant_address'],
            ],
        );

        event(new Registered($user));

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header title="Create an account" description="Enter your details below to create your account" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="register" class="flex flex-col gap-6">
        <!-- Name -->
        <div class="grid gap-2">
            <flux:input wire:model="name" id="name" label="{{ __('Name') }}" type="text" name="name" required autofocus autocomplete="name" placeholder="Full name" />
        </div>

        <!-- Email Address -->
        <div class="grid gap-2">
            <flux:input wire:model="email" id="email" label="{{ __('Email address') }}" type="email" name="email" required autocomplete="email" placeholder="email@example.com" />
        </div>

        <!-- Password -->
        <div class="grid gap-2">
            <flux:input
                wire:model="password"
                id="password"
                label="{{ __('Password') }}"
                type="password"
                name="password"
                required
                autocomplete="new-password"
                placeholder="Password"
            />
        </div>

        <!-- Confirm Password -->
        <div class="grid gap-2">
            <flux:input
                wire:model="password_confirmation"
                id="password_confirmation"
                label="{{ __('Confirm password') }}"
                type="password"
                name="password_confirmation"
                required
                autocomplete="new-password"
                placeholder="Confirm password"
            />
        </div>

        <div class="text-sm font-medium text-zinc-600 dark:text-zinc-400">
            {{ __('Restaurant details') }}
        </div>

        <!-- Restaurant Name -->
        <div class="grid gap-2">
            <flux:input wire:model="restaurant_name" id="restaurant_name" label="{{ __('Restaurant name') }}" type="text" name="restaurant_name" required autocomplete="organization" placeholder="Your restaurant's name" />
        </div>

        <!-- Restaurant Phone -->
        <div class="grid gap-2">
            <flux:input wire:model="restaurant_phone" id="restaurant_phone" label="{{ __('Restaurant phone') }}" type="text" name="restaurant_phone" required autocomplete="tel" placeholder="Restaurant phone number" />
        </div>

        <!-- Restaurant Address -->
        <div class="grid gap-2">
            <flux:input wire:model="restaurant_address" id="restaurant_address" label="{{ __('Restaurant address') }}" type="text" name="restaurant_address" required autocomplete="street-address" placeholder="Restaurant address" />
        </div>

        <div class="flex items-center justify-end">
            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Create account') }}
            </flux:button>
        </div>
    </form>

    <div class="space-x-1 text-center text-sm text-zinc-600 dark:text-zinc-400">
        Already have an account?
        <x-text-link href="{{ route('login') }}">Log in</x-text-link>
    </div>
</div>
