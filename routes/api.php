<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\ShippingWebhookController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\TransferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory API
|--------------------------------------------------------------------------
|
| Two security boundaries, deliberately different:
|
|   - Operator endpoints authenticate with a bearer token and are rate limited.
|     Reservation creation is throttled harder than reads because an unthrottled
|     reserve endpoint is a denial-of-service on stock itself: an attacker can
|     lock the entire catalogue without buying anything.
|
|   - The carrier webhook cannot present a bearer token, so it authenticates by
|     HMAC signature over the raw body instead. It is deliberately NOT behind the
|     token middleware — mixing the two would mean sharing our operator token
|     with a third party.
|
*/

Route::middleware('api.token')->group(function (): void {

    // ---- Read side -------------------------------------------------------
    Route::get('stock', [StockController::class, 'index']);
    Route::get('stock/{productId}/{warehouseId}', [StockController::class, 'show'])
        ->whereNumber(['productId', 'warehouseId']);
    Route::get('stock/{productId}/{warehouseId}/movements', [StockController::class, 'movements'])
        ->whereNumber(['productId', 'warehouseId']);
    Route::get('orders/consuming-inventory', [StockController::class, 'consumingOrders']);
    Route::get('reservations', [ReservationController::class, 'index']);

    // ---- Write side ------------------------------------------------------
    Route::post('reservations', [ReservationController::class, 'store'])
        ->middleware('throttle:reservations');

    Route::delete('reservations/{reservation}', [ReservationController::class, 'destroy']);

    Route::post('shipments', [ShipmentController::class, 'store']);
    Route::get('shipments/{shipment}', [ShipmentController::class, 'show']);

    Route::post('transfers', [TransferController::class, 'store']);
});

// ---- Carrier callbacks ---------------------------------------------------
// Signature-verified, not token-verified. See VerifyShippingWebhookSignature.
Route::post('webhooks/shipping/confirm', [ShippingWebhookController::class, 'confirm'])
    ->middleware('shipping.signature');
