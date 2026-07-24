<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedWebhook extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
