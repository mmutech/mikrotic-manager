<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RouterController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\VoucherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API
|--------------------------------------------------------------------------
|
| Stateless JSON endpoints for the mobile app. Authentication uses Sanctum
| personal access tokens. Subscription and renewal endpoints intentionally
| sit outside the "subscribed" group so an expired network administrator can
| still request or inspect a subscription. Every operational endpoint uses
| both "active" and "subscribed".
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->name('login');

    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::delete('/me', [AuthController::class, 'deactivateMe'])->name('me.deactivate');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::prefix('subscription')->name('subscription.')->group(function (): void {
            Route::get('/', [SubscriptionController::class, 'mine'])->name('mine');
            Route::get('/plans', [SubscriptionController::class, 'plans'])->name('plans');
            Route::post('/request', [SubscriptionController::class, 'requestRenewal'])->name('request');
        });

        Route::prefix('subscriptions')->name('subscriptions.')->group(function (): void {
            Route::get('/', [SubscriptionController::class, 'index'])->name('index');
            Route::get('/requests', [SubscriptionController::class, 'requests'])->name('requests');
            Route::put('/prices', [SubscriptionController::class, 'updatePrices'])->name('prices.update');
            Route::get('/{user}', [SubscriptionController::class, 'show'])->name('show');
            Route::post('/{user}/activate', [SubscriptionController::class, 'activate'])->name('activate');
            Route::post('/{user}/approve', [SubscriptionController::class, 'approve'])->name('approve');
            Route::post('/{user}/reject', [SubscriptionController::class, 'reject'])->name('reject');
            Route::post('/{user}/deactivate', [SubscriptionController::class, 'deactivate'])->name('deactivate');
        });
    });

    Route::middleware(['auth:sanctum', 'active', 'subscribed'])->group(function (): void {
        Route::prefix('routers')->name('routers.')->group(function (): void {
            Route::get('/', [RouterController::class, 'index'])->name('index');
            Route::get('/{router}', [RouterController::class, 'show'])->name('show');
            Route::get('/{router}/hotspot-profiles', [RouterController::class, 'hotspotProfiles'])->name('hotspot-profiles');
            Route::post('/{router}/test', [RouterController::class, 'test'])->name('test');
        });

        Route::get('/router-status', [ReportController::class, 'routers'])->name('router-status');

        Route::prefix('plans')->name('plans.')->group(function (): void {
            Route::get('/', [PlanController::class, 'index'])->name('index');
            Route::post('/', [PlanController::class, 'store'])->name('store');
            Route::get('/{plan}', [PlanController::class, 'show'])->name('show');
            Route::patch('/{plan}', [PlanController::class, 'update'])->name('update');
            Route::delete('/{plan}', [PlanController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('vouchers')->name('vouchers.')->group(function (): void {
            Route::get('/', [VoucherController::class, 'index'])->name('index');
            Route::get('/stats', [VoucherController::class, 'stats'])->name('stats');
            Route::post('/generate', [VoucherController::class, 'generate'])->name('generate');
            Route::post('/provision', [VoucherController::class, 'provisionBulk'])->name('provision-bulk');
            Route::post('/revoke', [VoucherController::class, 'revokeBulk'])->name('revoke-bulk');
            Route::get('/{voucher}', [VoucherController::class, 'show'])->name('show');
            Route::patch('/{voucher}', [VoucherController::class, 'update'])->name('update');
            Route::post('/{voucher}/provision', [VoucherController::class, 'provision'])->name('provision');
            Route::post('/{voucher}/revoke', [VoucherController::class, 'revoke'])->name('revoke');
        });

        Route::prefix('reports')->name('reports.')->group(function (): void {
            Route::get('/', [ReportController::class, 'overview'])->name('overview');
            Route::get('/vouchers', [ReportController::class, 'vouchers'])->name('vouchers');
            Route::get('/revenue', [ReportController::class, 'revenue'])->name('revenue');
            Route::get('/routers', [ReportController::class, 'routers'])->name('routers');
            Route::get('/subscriptions', [ReportController::class, 'subscriptions'])->name('subscriptions');
        });
    });
});
