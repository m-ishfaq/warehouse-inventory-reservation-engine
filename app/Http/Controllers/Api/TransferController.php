<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\Services\InventoryTransferService;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransferStockRequest;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;

class TransferController extends Controller
{
    public function __construct(private readonly InventoryTransferService $transfers) {}

    public function store(TransferStockRequest $request): JsonResponse
    {
        $product = Product::query()->findOrFail($request->validated('product_id'));
        $from = Warehouse::query()->findOrFail($request->validated('from_warehouse_id'));
        $to = Warehouse::query()->findOrFail($request->validated('to_warehouse_id'));

        $result = $this->transfers->transfer(
            product: $product,
            from: $from,
            to: $to,
            qty: (int) $request->validated('qty'),
            idempotencyKey: $request->idempotencyKey(),
            reason: $request->validated('reason'),
        );

        return response()->json(['data' => $result], 201);
    }
}
