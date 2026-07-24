<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Inventory\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'warehouse_id' => Warehouse::factory(),
            'status' => ShipmentStatus::Pending,
            'attempts' => 0,
        ];
    }

    /**
     * Handed to the carrier, outcome unknown — the state a timeout leaves
     * behind, and the one worth testing hardest.
     */
    public function dispatched(?string $providerRef = null): static
    {
        return $this->state(fn (): array => [
            'status' => ShipmentStatus::Dispatched,
            'provider_ref' => $providerRef ?? 'MOCK-'.fake()->unique()->bothify('??######'),
            'dispatched_at' => now(),
            'attempts' => 1,
        ]);
    }
}
