<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'qty_ordered' => fake()->numberBetween(1, 10),
            'qty_reserved' => 0,
            'qty_shipped' => 0,
            'qty_cancelled' => 0,
        ];
    }

    public function forQuantity(int $qty): static
    {
        return $this->state(fn (): array => ['qty_ordered' => $qty]);
    }
}
