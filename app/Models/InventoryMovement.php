<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Inventory\Enums\MovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The ledger. APPEND ONLY.
 *
 * The booted() guards below are not decoration: making the immutability
 * structural means a future controller, console command, or careless
 * `$movement->update()` cannot quietly rewrite history and break the
 * reconciliation guarantee that `inventory:verify` depends on.
 *
 * @property MovementType $type
 * @property int $qty
 * @property int $on_hand_delta
 * @property int $reserved_delta
 */
class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'type',
        'qty',
        'on_hand_delta',
        'reserved_delta',
        'on_hand_after',
        'reserved_after',
        'reference_type',
        'reference_id',
        'actor',
        'context',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'qty' => 'integer',
            'on_hand_delta' => 'integer',
            'reserved_delta' => 'integer',
            'on_hand_after' => 'integer',
            'reserved_after' => 'integer',
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Inventory movements are immutable; corrections must be posted as a new adjustment movement.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Inventory movements are immutable and cannot be deleted.');
        });
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
