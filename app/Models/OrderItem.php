<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $qty_ordered
 * @property int $qty_reserved
 * @property int $qty_shipped
 * @property int $qty_cancelled
 */
class OrderItem extends Model
{
    /** @use HasFactory<\Database\Factories\OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'qty_ordered',
        'qty_reserved',
        'qty_shipped',
        'qty_cancelled',
    ];

    protected function casts(): array
    {
        return [
            'qty_ordered' => 'integer',
            'qty_reserved' => 'integer',
            'qty_shipped' => 'integer',
            'qty_cancelled' => 'integer',
        ];
    }

    /**
     * Quantity still needing a reservation before this line can ship.
     */
    public function qtyOutstanding(): int
    {
        return max(0, $this->qty_ordered - $this->qty_reserved - $this->qty_shipped - $this->qty_cancelled);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
