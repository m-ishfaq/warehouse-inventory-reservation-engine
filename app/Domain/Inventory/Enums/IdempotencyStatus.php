<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

enum IdempotencyStatus: string
{
    /**
     * Claimed but not finished. Only visible to other connections once the
     * claiming transaction commits — which it only does on success — so in
     * practice this is what a *concurrent* duplicate sees, never a stale one.
     */
    case InProgress = 'in_progress';

    case Completed = 'completed';
}
