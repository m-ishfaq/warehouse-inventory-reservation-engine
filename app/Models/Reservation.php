<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Inventory\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $qty
 * @property int $qty_consumed
 * @property int $qty_released
 * @property ReservationStatus $status
 * @property Carbon|null $expires_at
 */
class Reservation extends Model
{
    /** @use HasFactory<\Database\Factories\ReservationFactory> */
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'product_id',
        'warehouse_id',
        'qty',
        'qty_consumed',
        'qty_released',
        'status',
        'expires_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'qty_consumed' => 'integer',
            'qty_released' => 'integer',
            'status' => ReservationStatus::class,
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Stock this reservation is still holding — the amount a release or expiry
     * must hand back, and the ceiling on what a shipment may still consume.
     */
    public function qtyOutstanding(): int
    {
        return max(0, $this->qty - $this->qty_consumed - $this->qty_released);
    }

    public function hasExpired(?Carbon $at = null): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->lessThanOrEqualTo($at ?? now());
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
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

    /** @return HasMany<ReservationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ReservationEvent::class);
    }

    /** @return HasMany<ShipmentItem, $this> */
    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    /** @param Builder<$this> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', ReservationStatus::openValues());
    }

    /** @param Builder<$this> $query */
    public function scopeExpiredBy(Builder $query, Carbon $at): void
    {
        $query->open()->whereNotNull('expires_at')->where('expires_at', '<=', $at);
    }
}
