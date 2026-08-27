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

require __DIR__.'/auth.php';
