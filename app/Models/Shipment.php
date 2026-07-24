<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Inventory\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ShipmentStatus $status
 * @property int $attempts
 */
class Shipment extends Model
{
    /** @use HasFactory<\Database\Factories\ShipmentFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'warehouse_id',
        'status',
        'provider_ref',
        'attempts',
        'last_error',
        'dispatched_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'attempts' => 'integer',
            'dispatched_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<ShipmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }
}
