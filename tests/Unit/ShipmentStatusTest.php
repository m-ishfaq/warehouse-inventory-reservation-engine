<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Inventory\Enums\ShippingOutcome;
use App\Domain\Inventory\Enums\ShipmentStatus;
use PHPUnit\Framework\TestCase;

class ShipmentStatusTest extends TestCase
{
    public function test_a_shipped_shipment_is_terminal(): void
    {
        $this->assertSame([], ShipmentStatus::Shipped->allowedTransitions());

        foreach (ShipmentStatus::cases() as $target) {
            $this->assertFalse(ShipmentStatus::Shipped->canTransitionTo($target));
        }
    }

    public function test_a_failed_shipment_can_be_retried(): void
    {
        $this->assertTrue(ShipmentStatus::Failed->canTransitionTo(ShipmentStatus::Dispatched));
        $this->assertTrue(ShipmentStatus::Failed->isRetryable());
    }

    public function test_a_dispatched_shipment_cannot_go_back_to_pending(): void
    {
        // Going backwards would let a retry re-dispatch a consignment the
        // carrier may already have taken.
        $this->assertFalse(ShipmentStatus::Dispatched->canTransitionTo(ShipmentStatus::Pending));
    }

    public function test_a_partially_shipped_shipment_can_be_completed(): void
    {
        $this->assertTrue(ShipmentStatus::PartiallyShipped->canTransitionTo(ShipmentStatus::Shipped));
        $this->assertTrue(ShipmentStatus::PartiallyShipped->canTransitionTo(ShipmentStatus::PartiallyShipped));
    }

    public function test_only_shipped_states_have_consumed_inventory(): void
    {
        $this->assertTrue(ShipmentStatus::Shipped->hasConsumedInventory());
        $this->assertTrue(ShipmentStatus::PartiallyShipped->hasConsumedInventory());

        // Critically, `dispatched` has NOT consumed inventory. That is the whole
        // reason a timeout is recoverable.
        $this->assertFalse(ShipmentStatus::Dispatched->hasConsumedInventory());
        $this->assertFalse(ShipmentStatus::Pending->hasConsumedInventory());
        $this->assertFalse(ShipmentStatus::Failed->hasConsumedInventory());
    }

    public function test_only_a_timeout_is_worth_retrying(): void
    {
        $this->assertTrue(ShippingOutcome::Timeout->isRetryable());

        // Retrying a permanent rejection just burns worker capacity.
        $this->assertFalse(ShippingOutcome::PermanentFailure->isRetryable());
        $this->assertFalse(ShippingOutcome::Success->isRetryable());
    }

    public function test_outcomes_are_classified_correctly(): void
    {
        $this->assertTrue(ShippingOutcome::Success->isSuccessful());
        $this->assertTrue(ShippingOutcome::DelayedSuccess->isSuccessful());
        $this->assertTrue(ShippingOutcome::DuplicateConfirmation->isSuccessful());

        $this->assertFalse(ShippingOutcome::Timeout->isSuccessful());
        $this->assertFalse(ShippingOutcome::PermanentFailure->isSuccessful());
    }
}
