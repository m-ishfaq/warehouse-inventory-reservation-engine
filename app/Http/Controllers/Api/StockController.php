<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\DTOs\StockSnapshot;
use App\Domain\Inventory\Services\StockQueryService;
use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The read endpoints: available / reserved / on-hand / shipped-to-date, movement
 * history, which reservations remain open, and which orders consumed inventory.
 *
 * Picked and packed stock are deliberately absent — this engine implements
 * reserve → ship, so reporting them would mean publishing two permanent zeroes.
 * See docs/ARCHITECTURE.md.
 */
class StockController extends Controller
{
    public function __construct(private readonly StockQueryService $stock) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['sometimes', 'integer'],
            'product_id' => ['sometimes', 'integer'],
        ]);

        $snapshots = $this->stock->snapshots(
            isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null,
            isset($validated['product_id']) ? (int) $validated['product_id'] : null,
        );

        return response()->json([
            'data' => $snapshots->map(static fn (StockSnapshot $snapshot): array => $snapshot->toArray())->values(),
        ]);
    }

    public function show(int $productId, int $warehouseId): JsonResponse
    {
        $snapshot = $this->stock->snapshot($productId, $warehouseId);

        if ($snapshot === null) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'No stock position exists for that product and warehouse.',
            ], 404);
        }

        return response()->json(['data' => $snapshot->toArray()]);
    }

    /**
     * The audit trail. Every number the other endpoints return is derivable
     * from this list, which is the point of an append-only ledger.
     */
    public function movements(Request $request, int $productId, int $warehouseId): JsonResponse
    {
        $limit = (int) $request->integer('limit', 100);

        $movements = $this->stock->movementHistory($productId, $warehouseId, min($limit, 500));

        return response()->json([
            'data' => $movements->map(static fn (InventoryMovement $movement): array => [
                'id' => $movement->getKey(),
                'type' => $movement->type->value,
                'label' => $movement->type->label(),
                'qty' => $movement->qty,
                'on_hand_delta' => $movement->on_hand_delta,
                'reserved_delta' => $movement->reserved_delta,
                'on_hand_after' => $movement->on_hand_after,
                'reserved_after' => $movement->reserved_after,
                'reference_type' => $movement->reference_type !== null ? class_basename($movement->reference_type) : null,
                'reference_id' => $movement->reference_id,
                'actor' => $movement->actor,
                'occurred_at' => $movement->occurred_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function consumingOrders(): JsonResponse
    {
        return response()->json(['data' => $this->stock->ordersThatConsumedInventory()]);
    }
}
