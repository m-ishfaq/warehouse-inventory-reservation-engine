<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Inventory\Enums\ReservationStatus;
use PHPUnit\Framework\TestCase;

/**
 * The state machine is the guard that makes duplicate confirmations safe even
 * if every other layer were bypassed. It deserves tests of its own, with no
 * database in the way.
 */
class ReservationStatusTest extends TestCase
{
    public function test_only_active_and_partially_consumed_hold_stock(): void
    {
        $this->assertTrue(ReservationStatus::Active->isOpen());
        $this->assertTrue(ReservationStatus::PartiallyConsumed->isOpen());

        $this->assertFalse(ReservationStatus::Consumed->isOpen());
        $this->assertFalse(ReservationStatus::Released->isOpen());
        $this->assertFalse(ReservationStatus::Expired->isOpen());
    }

    public function test_terminal_states_accept_no_further_transitions(): void
    {
        foreach ([ReservationStatus::Consumed, ReservationStatus::Released, ReservationStatus::Expired] as $terminal) {
            $this->assertSame([], $terminal->allowedTransitions(), $terminal->value.' should be terminal.');

            foreach (ReservationStatus::cases() as $target) {
                $this->assertFalse(
                    $terminal->canTransitionTo($target),
                    sprintf('%s must not transition to %s.', $terminal->value, $target->value),
                );
            }
        }
    }

    public function test_an_active_reservation_can_reach_every_outcome(): void
    {
        $active = ReservationStatus::Active;

        $this->assertTrue($active->canTransitionTo(ReservationStatus::PartiallyConsumed));
        $this->assertTrue($active->canTransitionTo(ReservationStatus::Consumed));
        $this->assertTrue($active->canTransitionTo(ReservationStatus::Released));
        $this->assertTrue($active->canTransitionTo(ReservationStatus::Expired));
    }

    public function test_a_partially_consumed_reservation_can_take_further_shipments(): void
    {
        // Self-transition must be legal: a second partial shipment leaves the
        // reservation in the same state, and that is not an error.
        $this->assertTrue(
            ReservationStatus::PartiallyConsumed->canTransitionTo(ReservationStatus::PartiallyConsumed),
        );
    }

    public function test_a_consumed_reservation_can_never_go_back_to_active(): void
    {
        // This is the guard that stops a replayed confirmation from resurrecting
        // a settled claim and deducting stock a second time.
        $this->assertFalse(ReservationStatus::Consumed->canTransitionTo(ReservationStatus::Active));
    }

    public function test_open_values_lists_exactly_the_stock_holding_statuses(): void
    {
        $this->assertSame(
            ['active', 'partially_consumed'],
            array_values(ReservationStatus::openValues()),
        );
    }

    public function test_every_case_has_a_label_and_a_badge_class(): void
    {
        foreach (ReservationStatus::cases() as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertNotSame('', $case->badgeClass());
        }
    }
}
