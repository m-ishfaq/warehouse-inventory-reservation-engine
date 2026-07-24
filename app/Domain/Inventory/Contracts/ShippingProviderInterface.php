<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Contracts;

use App\Domain\Inventory\DTOs\ShippingResponse;
use App\Models\Shipment;

/**
 * The seam between the inventory engine and the outside world.
 *
 * The domain never knows whether it is talking to a mock, DHL, or Aramex. That
 * is the Dependency Inversion half of SOLID doing real work here: swapping in a
 * live carrier is a container binding in AppServiceProvider and nothing else.
 */
interface ShippingProviderInterface
{
    /**
     * Hand a shipment to the carrier.
     *
     * Implementations MUST NOT throw for business failures — a rejected or timed
     * out shipment is a normal outcome and is reported through the returned
     * ShippingResponse. Throwing is reserved for genuinely unexpected faults,
     * which the queue worker will then retry.
     */
    public function dispatch(Shipment $shipment): ShippingResponse;

    /**
     * Identifier recorded on the shipment and on webhook payloads.
     */
    public function name(): string;
}
