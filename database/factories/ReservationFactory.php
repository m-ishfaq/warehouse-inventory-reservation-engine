<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Inventory\Enums\ReservationStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 *
 * NOTE: building a reservation through this factory creates the row but does
 * NOT post a ledger movement, so the reserved quantity will not be reflected in
 * inventory. That is intentional — it lets tests construct odd states directly.
 * Tests that assert on stock levels should go through ReservationService.
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'qty' => 5,
            'qty_consumed' => 0,
            'qty_released' => 0,
            'status' => ReservationStatus::Active,
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function expiringAt(\DateTimeInterface $at): static
    {
        return $this->state(fn (): array => ['expires_at' => $at]);
    }

    public function alreadyExpired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function neverExpires(): static
    {
        return $this->state(fn (): array => ['expires_at' => null]);
    }
}
