<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Inventory\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    protected $fillable = ['order_number', 'customer_ref', 'status'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<Shipment, $this> */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /** @return HasManyThrough<Reservation, OrderItem, $this> */
    public function reservations(): HasManyThrough
    {
        return $this->hasManyThrough(Reservation::class, OrderItem::class);
    }
}
