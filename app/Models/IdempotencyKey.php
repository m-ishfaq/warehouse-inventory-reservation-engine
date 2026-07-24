<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Inventory\Enums\IdempotencyStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property IdempotencyStatus $status
 * @property array<string, mixed>|null $response
 */
class IdempotencyKey extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'scope',
        'key',
        'status',
        'response',
        'locked_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => IdempotencyStatus::class,
            'response' => 'array',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === IdempotencyStatus::Completed;
    }
}
