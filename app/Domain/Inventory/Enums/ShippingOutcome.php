<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * The five behaviours the quest requires the mock shipping provider to exhibit.
 *
 * Modelling them as an enum rather than as random exceptions means the test
 * suite and `demo:scenario` can pin a specific one and assert the engine's
 * response, instead of hoping the dice land the right way.
 */
enum ShippingOutcome: string
{
    /** Provider accepts and confirms immediately. */
    case Success = 'success';

    /** Provider rejects outright. Retrying will not help. */
    case PermanentFailure = 'permanent_failure';

    /** No answer. We do not know whether it shipped — the dangerous one. */
    case Timeout = 'timeout';

    /** Ships, then sends the same confirmation event twice. */
    case DuplicateConfirmation = 'duplicate_confirmation';

    /** Hangs, then eventually reports success. */
    case DelayedSuccess = 'delayed_success';

    public function isSuccessful(): bool
    {
        return match ($this) {
            self::Success, self::DuplicateConfirmation, self::DelayedSuccess => true,
            self::PermanentFailure, self::Timeout => false,
        };
    }

    /**
     * Should the queue worker try this shipment again?
     *
     * A timeout is retryable because we genuinely do not know the outcome; a
     * permanent failure is not, because retrying only burns worker capacity.
     */
    public function isRetryable(): bool
    {
        return $this === self::Timeout;
    }

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::PermanentFailure => 'Permanent failure',
            self::Timeout => 'Timeout',
            self::DuplicateConfirmation => 'Success with duplicate confirmation',
            self::DelayedSuccess => 'Delayed success',
        };
    }
}
