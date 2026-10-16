<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\VpnServerController;
use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified', 'tenant'])->name('dashboard');

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/organizations/{organization}/switch', OrganizationSwitchController::class)->name('organizations.switch');
});

require __DIR__.'/auth.php';

Route::middleware(['auth', 'verified', 'tenant', 'throttle:60,1'])->group(function () {
    Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::get('/billing', [\App\Http\Controllers\BillingController::class, 'index'])->name('billing.index');
    Route::get('/vpn', [\App\Http\Controllers\VpnController::class, 'index'])->name('vpn.index');
    Route::get('/vpn-servers', [VpnServerController::class, 'index'])->middleware('role:super-admin')->name('vpn-servers.index');
    Route::post('/vpn-servers', [VpnServerController::class, 'store'])->middleware('role:super-admin')->name('vpn-servers.store');
    Route::post('/vpn-servers/{server}/default', [VpnServerController::class, 'makeDefault'])->middleware('role:super-admin')->name('vpn-servers.default');
    Route::post('/vpn-servers/{server}/toggle', [VpnServerController::class, 'toggle'])->middleware('role:super-admin')->name('vpn-servers.toggle');
    Route::post('/vpn-servers/{server}/check', [VpnServerController::class, 'check'])->middleware('role:super-admin')->name('vpn-servers.check');
    Route::get('/automation', [AutomationController::class, 'index'])->name('automation.index');
    Route::post('/vpn/{subscription}/provision', [\App\Http\Controllers\VpnController::class, 'provision'])->name('vpn.provision');
    Route::post('/vpn/peers/{peer}/retry', [\App\Http\Controllers\VpnController::class, 'retry'])->name('vpn.retry');
    Route::get('/resellers', [\App\Http\Controllers\ResellerController::class, 'index'])->name('resellers.index');
    Route::post('/resellers', [\App\Http\Controllers\ResellerController::class, 'register'])->name('resellers.register');
    Route::post('/resellers/{account}', [\App\Http\Controllers\ResellerController::class, 'update'])->name('resellers.update');
    Route::post('/resellers/{account}/customers', [\App\Http\Controllers\ResellerController::class, 'customer'])->name('resellers.customer');
    Route::post('/billing/invoices/{invoice}/checkout', [\App\Http\Controllers\MidtransController::class, 'checkout'])->name('billing.checkout');
    Route::post('/billing/plans', [\App\Http\Controllers\BillingController::class, 'plan'])->name('billing.plans');
    Route::post('/billing/orders', [\App\Http\Controllers\BillingController::class, 'order'])->name('billing.orders');
    Route::post('/billing/invoices/{invoice}/approve', [\App\Http\Controllers\BillingController::class, 'approve'])->name('billing.approve');
});

Route::post('/webhooks/midtrans', [\App\Http\Controllers\MidtransController::class, 'notification'])
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
    ->middleware('throttle:120,1');
