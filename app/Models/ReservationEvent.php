<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Inventory\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Never updated, never deleted.
 */
class ReservationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'reservation_id',
        'from_status',
        'to_status',
        'qty_delta',
        'reason',
        'actor',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => ReservationStatus::class,
            'to_status' => ReservationStatus::class,
            'qty_delta' => 'integer',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
