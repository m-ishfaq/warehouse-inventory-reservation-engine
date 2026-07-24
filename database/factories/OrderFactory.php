<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Inventory\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'SO-'.Str::upper(Str::random(10)),
            'customer_ref' => fake()->company(),
            'status' => OrderStatus::Draft,
        ];
    }
}
