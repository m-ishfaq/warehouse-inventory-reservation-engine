<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $qty_requested
 * @property int $qty_shipped
 */
class ShipmentItem extends Model
{
    protected $fillable = [
        'shipment_id',
        'reservation_id',
        'qty_requested',
        'qty_shipped',
    ];

    protected function casts(): array
    {
        return [
            'qty_requested' => 'integer',
            'qty_shipped' => 'integer',
        ];
    }

    public function isFullyShipped(): bool
    {
        return $this->qty_shipped >= $this->qty_requested;
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
