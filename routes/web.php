<?php

declare(strict_types=1);

use App\Http\Controllers\Web\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Operator dashboard
|--------------------------------------------------------------------------
|
| Read-only. Every mutation lives in the API or the console so there is exactly
| one implementation of each operation. These routes inherit the `web` group's
| CSRF protection; the JSON endpoints below are GETs, so they are safe to poll.
|
*/

Route::redirect('/', '/dashboard');

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/dashboard/snapshot', [DashboardController::class, 'snapshot'])->name('dashboard.snapshot');
Route::get('/dashboard/movements/{productId}/{warehouseId}', [DashboardController::class, 'movements'])
    ->whereNumber(['productId', 'warehouseId'])
    ->name('dashboard.movements');
