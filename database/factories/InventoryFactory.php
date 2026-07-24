<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inventory>
 */
class InventoryFactory extends Factory
{
    protected $model = Inventory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'on_hand_qty' => 100,
            'reserved_qty' => 0,
            'picked_qty' => 0,
            'packed_qty' => 0,
        ];
    }

    /**
     * Exactly `n` units on hand and nothing reserved.
     *
     * Used constantly in tests — "one item left" is the single most important
     * fixture in this whole system, because it is where every concurrency bug
     * shows up.
     */
    public function withStock(int $onHand, int $reserved = 0): static
    {
        return $this->state(fn (): array => [
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
        ]);
    }

    public function empty(): static
    {
        return $this->withStock(0);
    }
}
