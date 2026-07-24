<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Inventory\DTOs\ReservationLine;
use App\Domain\Inventory\DTOs\StockSnapshot;
use App\Domain\Inventory\Enums\MovementType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MovementTypeTest extends TestCase
{
    public function test_shipping_is_the_only_type_that_touches_both_buckets(): void
    {
        $both = array_filter(
            MovementType::cases(),
            static fn (MovementType $type): bool => $type->touchesOnHand() && $type->touchesReserved(),
        );

        // If a second type ever needs both, that is a design decision worth
        // noticing — hence the assertion rather than a comment.
        $this->assertSame([MovementType::Ship], array_values($both));
    }

    public function test_reservation_movements_never_touch_physical_stock(): void
    {
        foreach ([MovementType::Reserve, MovementType::Release, MovementType::Expire] as $type) {
            $this->assertFalse($type->touchesOnHand(), $type->value.' must not move physical stock.');
            $this->assertTrue($type->touchesReserved());
        }
    }

    public function test_transfers_move_physical_stock_without_affecting_claims(): void
    {
        foreach ([MovementType::TransferIn, MovementType::TransferOut] as $type) {
            $this->assertTrue($type->touchesOnHand());
            $this->assertFalse($type->touchesReserved());
        }
    }

    public function test_a_reservation_line_rejects_a_non_positive_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReservationLine(1, 1, 1, 0);
    }

    public function test_a_reservation_line_key_identifies_the_stock_position(): void
    {
        $line = new ReservationLine(orderItemId: 9, productId: 4, warehouseId: 7, qty: 3);

        $this->assertSame('4:7', $line->pairKey());
    }

    public function test_available_is_on_hand_minus_reserved(): void
    {
        $snapshot = new StockSnapshot(
            productId: 1,
            productSku: 'SKU-1',
            warehouseId: 1,
            warehouseCode: 'WH-1',
            onHand: 10,
            reserved: 4,
            shippedToDate: 25,
        );

        $this->assertSame(6, $snapshot->available());
        $this->assertSame(6, $snapshot->toArray()['available']);
    }
}
