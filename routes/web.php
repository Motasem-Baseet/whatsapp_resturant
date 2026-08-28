<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
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

Route::middleware(['auth', 'verified', 'role:owner'])->prefix('customers')->name('customers.')->group(function () {
    Volt::route('/', 'customers.index')->name('index');
    Volt::route('create', 'customers.create')->name('create');
    Volt::route('{customer}/edit', 'customers.edit')->name('edit');
});

Route::middleware(['auth', 'verified', 'role:owner|cashier'])->group(function () {
    Volt::route('inbox', 'inbox.index')->name('inbox.index');
    Volt::route('conversations/create', 'inbox.conversations.create')->name('conversations.create');
    Volt::route('conversations/{conversation}', 'inbox.conversations.show')->name('conversations.show');
});

Route::middleware(['auth', 'verified', 'role:owner|cashier'])->prefix('orders')->name('orders.')->group(function () {
    Volt::route('/', 'orders.index')->name('index');
    Volt::route('create', 'orders.create')->name('create');
    Volt::route('{order}', 'orders.show')->name('show');
});

require __DIR__.'/auth.php';
