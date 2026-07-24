<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Inventory\Services\StockQueryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReleaseReservationRequest;
use App\Http\Requests\ReserveStockRequest;
use App\Models\Order;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controllers stay thin on purpose: parse, delegate, format.
 *
 * No locking, no transactions, no inventory arithmetic here. If any of that
 * leaked into the HTTP layer it would be unavailable to the queue worker and the
 * artisan commands, and the two paths would drift.
 */
class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly StockQueryService $stock,
    ) {}

    /**
     * Open claims, optionally filtered.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['sometimes', 'integer'],
            'product_id' => ['sometimes', 'integer'],
        ]);

        $reservations = $this->stock->openReservations(
            isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
            isset($validated['product_id']) ? (int) $validated['product_id'] : null,
        );

        return response()->json([
            'data' => $reservations->map(fn (Reservation $reservation): array => [
                'id' => $reservation->getKey(),
                'order_item_id' => $reservation->order_item_id,
                'sku' => $reservation->product?->sku,
                'warehouse' => $reservation->warehouse?->code,
                'qty' => $reservation->qty,
                'qty_consumed' => $reservation->qty_consumed,
                'qty_released' => $reservation->qty_released,
                'qty_outstanding' => $reservation->qtyOutstanding(),
                'status' => $reservation->status->value,
                'expires_at' => $reservation->expires_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Reserve stock for one or more order lines.
     *
     * Pass an Idempotency-Key header to make the call safe to retry. Without
     * one, a client that retries on a network blip will reserve twice — which is
     * why the header is documented as strongly recommended in the README.
     */
    public function store(ReserveStockRequest $request): JsonResponse
    {
        $order = Order::query()->findOrFail($request->validated('order_id'));

        $result = $this->reservations->reserve(
            order: $order,
            lines: $request->lines(),
            idempotencyKey: $request->idempotencyKey(),
            allowPartial: $request->boolean('allow_partial', false),
            ttlMinutes: $request->has('ttl_minutes') ? (int) $request->validated('ttl_minutes') : null,
        );

        return response()->json([
            'data' => $result->toArray(),
            // Tells the client this response came from the idempotency store
            // rather than from fresh work — useful when debugging retries.
            'replayed' => $result->replayed,
        ], $result->replayed ? 200 : 201);
    }

    /**
     * Release a claim, fully or partially.
     */
    public function destroy(ReleaseReservationRequest $request, Reservation $reservation): JsonResponse
    {
        $released = $this->reservations->release(
            reservation: $reservation,
            qty: $request->has('qty') ? (int) $request->validated('qty') : null,
            reason: (string) ($request->validated('reason') ?? 'manual_release'),
            idempotencyKey: $request->idempotencyKey(),
        );

        return response()->json([
            'data' => [
                'reservation_id' => $reservation->getKey(),
                'released' => $released,
                'status' => $reservation->refresh()->status->value,
                'qty_outstanding' => $reservation->qtyOutstanding(),
            ],
        ]);
    }
}
