<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

/**
 * The provider did not answer. We do not know whether the goods shipped.
 *
 * This is thrown so the queue worker retries. It is safe to retry precisely
 * because inventory is not deducted on dispatch, only on confirmation: the worst
 * case is a shipment sitting in `dispatched` holding a reservation, which a
 * later confirmation or a manual cancellation resolves. Nothing is ever
 * double-deducted, and nothing is lost.
 */
final class ShipmentTimeoutException extends InventoryException
{
    public static function for(int $shipmentId, ?string $providerRef = null): self
    {
        return new self(
            sprintf('Shipping provider timed out for shipment %d; outcome unknown, will retry.', $shipmentId),
            ['shipment_id' => $shipmentId, 'provider_ref' => $providerRef],
        );
    }

    public function errorCode(): string
    {
        return 'shipment_timeout';
    }

    public function httpStatus(): int
    {
        return 504;
    }
}
