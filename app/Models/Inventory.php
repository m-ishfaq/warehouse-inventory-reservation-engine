<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Materialised stock position for one (product, warehouse) pair.
 *
 * Deliberately has NO business methods that mutate quantities. Everything goes
 * through InventoryLedger so that no quantity can ever change without an
 * accompanying movement row. If you find yourself wanting `$inventory->reserve()`
 * here, that is the ledger's job.
 *
 * @property int $on_hand_qty
 * @property int $reserved_qty
 * @property int $picked_qty
 * @property int $packed_qty
 */
class Inventory extends Model
{
    /** @use HasFactory<\Database\Factories\InventoryFactory> */
    use HasFactory;

    protected $table = 'inventory';

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'on_hand_qty',
        'reserved_qty',
        'picked_qty',
        'packed_qty',
    ];

    protected function casts(): array
    {
        return [
            'on_hand_qty' => 'integer',
            'reserved_qty' => 'integer',
            'picked_qty' => 'integer',
            'packed_qty' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * The only number the sales side is ever allowed to see.
     */
    public function availableQty(): int
    {
        return $this->on_hand_qty - $this->reserved_qty;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @param Builder<$this> $query */
    public function scopeForPair(Builder $query, int $productId, int $warehouseId): void
    {
        $query->where('product_id', $productId)->where('warehouse_id', $warehouseId);
    }
}
