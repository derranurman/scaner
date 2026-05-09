<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderImportController;
use App\Http\Controllers\PackingReportController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VariantController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

// Auth
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// Authenticated
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Packing (admin+packing)
    Route::middleware('role:admin,packing')->group(function () {
        Route::get('/scan', [ScanController::class, 'index'])->name('scan.index');
        Route::post('/scan/lookup', [ScanController::class, 'lookup'])->name('scan.lookup');
        Route::post('/scan/confirm', [ScanController::class, 'confirm'])->name('scan.confirm');
    });

    // Admin only
    Route::middleware('role:admin')->group(function () {
        // Products & Variants
        Route::resource('products', ProductController::class);
        Route::post('products/{product}/variants', [VariantController::class, 'store'])->name('variants.store');
        Route::put('variants/{variant}', [VariantController::class, 'update'])->name('variants.update');
        Route::delete('variants/{variant}', [VariantController::class, 'destroy'])->name('variants.destroy');
        Route::post('variants/{variant}/adjust', [VariantController::class, 'adjust'])->name('variants.adjust');

        // Orders
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');

        // Import
        Route::get('orders-import', [OrderImportController::class, 'show'])->name('orders.import.show');
        Route::post('orders-import', [OrderImportController::class, 'import'])->name('orders.import');
        Route::get('orders-import/template', [OrderImportController::class, 'template'])->name('orders.import.template');

        // Reports
        Route::get('reports/packing', [PackingReportController::class, 'index'])->name('reports.packing');
        Route::get('reports/packing/export', [PackingReportController::class, 'export'])->name('reports.packing.export');

        // Users
        Route::resource('users', UserController::class)->except(['show']);
    });
});
