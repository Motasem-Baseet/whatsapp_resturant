<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Volt::route('dashboard', 'dashboard')
    ->middleware(['auth', 'verified', 'onboarding.incomplete'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

Route::middleware(['auth', 'verified', 'role:owner'])->group(function () {
    Volt::route('settings/whatsapp', 'settings.whatsapp')->name('settings.whatsapp');
    Volt::route('settings/restaurant', 'settings.restaurant')->name('settings.restaurant');
    Volt::route('onboarding', 'onboarding.show')->name('onboarding.show');
});

Route::middleware(['auth', 'verified', 'role:owner'])->prefix('employees')->name('employees.')->group(function () {
    Volt::route('/', 'employees.index')->name('index');
    Volt::route('create', 'employees.create')->name('create');
    Volt::route('{employee}/edit', 'employees.edit')->name('edit');
});

Route::middleware(['auth', 'verified', 'role:owner'])->prefix('menu')->name('menu.')->group(function () {
    Volt::route('categories', 'menu.categories.index')->name('categories.index');
    Volt::route('categories/create', 'menu.categories.create')->name('categories.create');
    Volt::route('categories/{category}/edit', 'menu.categories.edit')->name('categories.edit');

    Volt::route('products', 'menu.products.index')->name('products.index');
    Volt::route('products/create', 'menu.products.create')->name('products.create');
    Volt::route('products/{product}/edit', 'menu.products.edit')->name('products.edit');
});

// The "create" route must be registered before the "{customer}"
// wildcard route below - otherwise Laravel would try to match
// /customers/create against {customer} first (since routes are matched
// in registration order), attempting to bind a Customer with route key
// "create" instead of reaching the create page.
Route::middleware(['auth', 'verified', 'role:owner'])->prefix('customers')->name('customers.')->group(function () {
    Volt::route('create', 'customers.create')->name('create');
    Volt::route('{customer}/edit', 'customers.edit')->name('edit');
});

Route::middleware(['auth', 'verified', 'role:owner|cashier'])->prefix('customers')->name('customers.')->group(function () {
    Volt::route('/', 'customers.index')->name('index');
    Volt::route('{customer}', 'customers.show')->name('show');
});

Route::middleware(['auth', 'verified', 'role:owner|cashier'])->group(function () {
    Volt::route('inbox', 'inbox.index')->name('inbox.index');
    Volt::route('conversations/create', 'inbox.conversations.create')->name('conversations.create');
    Volt::route('conversations/{conversation}', 'inbox.conversations.show')->name('conversations.show');
    Volt::route('conversations/{conversation}/orders/create', 'inbox.conversations.orders.create')->name('conversations.orders.create');
});

Route::middleware(['auth', 'verified', 'role:owner|cashier'])->prefix('orders')->name('orders.')->group(function () {
    Volt::route('/', 'orders.index')->name('index');
    Volt::route('create', 'orders.create')->name('create');
    Volt::route('{order}', 'orders.show')->name('show');
});

Route::middleware(['auth', 'verified', 'role:kitchen'])->prefix('kitchen')->name('kitchen.')->group(function () {
    Volt::route('orders', 'kitchen.orders.index')->name('orders.index');
    Volt::route('orders/{order}', 'kitchen.orders.show')->name('orders.show');
});

Route::middleware(['auth', 'verified', 'role:owner'])->prefix('reports')->name('reports.')->group(function () {
    Volt::route('orders', 'reports.orders')->name('orders');
});

require __DIR__.'/auth.php';
