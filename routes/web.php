<?php

use App\Http\Controllers\PaystackController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Every authenticated page is a Livewire single-file component. Access
| control is enforced in two layers: middleware here (guest/auth/active)
| and policies inside each component's mount() and action methods.
|
*/

Route::redirect('/', '/dashboard')->name('home');

Route::get('/paystack/callback', [PaystackController::class, 'callback'])->name('paystack.callback');
Route::post('/webhooks/paystack', [PaystackController::class, 'webhook'])->name('webhooks.paystack');

Route::middleware('guest')->group(function (): void {
    Route::livewire('/login', 'pages::auth.login')->name('login');
    Route::livewire('/forgot-password', 'pages::auth.forgot-password')->name('password.request');
    Route::livewire('/reset-password/{token}', 'pages::auth.reset-password')->name('password.reset');
});

Route::post('/logout', function () {
    Auth::guard('web')->logout();

    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::livewire('/dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('/routers', 'pages::routers.index')->name('routers.index');
    Route::livewire('/routers/create', 'pages::routers.create')->name('routers.create');
    Route::livewire('/routers/{router}', 'pages::routers.show')->name('routers.show');
    Route::livewire('/routers/{router}/edit', 'pages::routers.edit')->name('routers.edit');

    Route::livewire('/plans', 'pages::plans.index')->name('plans.index');
    Route::livewire('/plans/create', 'pages::plans.create')->name('plans.create');
    Route::livewire('/plans/{plan}', 'pages::plans.show')->name('plans.show');
    Route::livewire('/plans/{plan}/edit', 'pages::plans.edit')->name('plans.edit');

    Route::livewire('/vouchers', 'pages::vouchers.index')->name('vouchers.index');
    Route::livewire('/vouchers/generate', 'pages::vouchers.generate')->name('vouchers.generate');
    Route::livewire('/vouchers/{voucher}', 'pages::vouchers.show')->name('vouchers.show');

    Route::livewire('/customers', 'pages::customers.index')->name('customers.index');
    Route::livewire('/customers/create', 'pages::customers.create')->name('customers.create');
    Route::livewire('/customers/{customer}', 'pages::customers.show')->name('customers.show');
    Route::livewire('/customers/{customer}/edit', 'pages::customers.edit')->name('customers.edit');

    Route::livewire('/transactions', 'pages::transactions.index')->name('transactions.index');
    Route::livewire('/transactions/{transaction}', 'pages::transactions.show')->name('transactions.show');

    Route::livewire('/purchases', 'pages::purchases.index')->name('purchases.index');
    Route::livewire('/purchases/{purchase}', 'pages::purchases.show')->name('purchases.show');

    Route::livewire('/reports', 'pages::reports.index')->name('reports.index');

    Route::livewire('/users', 'pages::users.index')->name('users.index');
    Route::livewire('/users/create', 'pages::users.create')->name('users.create');
    Route::livewire('/users/{user}', 'pages::users.show')->name('users.show');
    Route::livewire('/users/{user}/edit', 'pages::users.edit')->name('users.edit');
    Route::livewire('/users/{user}/roles', 'pages::users.roles')->name('users.roles');
    Route::livewire('/users/{user}/routers', 'pages::users.routers')->name('users.routers');

    Route::livewire('/roles', 'pages::roles.index')->name('roles.index');
    Route::livewire('/roles/create', 'pages::roles.create')->name('roles.create');
    Route::livewire('/roles/{role}', 'pages::roles.show')->name('roles.show');
    Route::livewire('/roles/{role}/edit', 'pages::roles.edit')->name('roles.edit');
    Route::livewire('/roles/{role}/permissions', 'pages::roles.permissions')->name('roles.permissions');

    Route::livewire('/audit', 'pages::audit.index')->name('audit.index');

    Route::livewire('/account/password', 'pages::account.password')->name('account.password');
});
