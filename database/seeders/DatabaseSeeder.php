<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Inventory\Services\InventoryLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seed a small but realistic estate: 3 warehouses, 20 products, opening stock.
 *
 * IMPORTANT: opening stock is posted through the ledger as `receipt` movements
 * rather than written straight into the inventory table.
 *
 * That is not ceremony. If the seeder wrote quantities directly, the ledger and
 * the cache would disagree from the very first row, and `php artisan
 * inventory:verify` would report drift on a freshly installed system. Seeded
 * data has to obey the same invariant as production data, or the invariant is
 * decorative.
 */
class DatabaseSeeder extends Seeder
{
    private const OPENING_STOCK = [
        'main' => 250,
        'overflow' => 60,
        'returns' => 0,
    ];

    public function run(): void
    {
        $warehouses = collect([
            ['code' => 'WH-MAIN', 'name' => 'Alexandria Main DC', 'key' => 'main'],
            ['code' => 'WH-OVF', 'name' => 'Smouha Overflow', 'key' => 'overflow'],
            ['code' => 'WH-RET', 'name' => 'Returns & Quarantine', 'key' => 'returns'],
        ])->map(static function (array $data): Warehouse {
            $warehouse = Warehouse::query()->firstOrCreate(
                ['code' => $data['code']],
                ['name' => $data['name'], 'is_active' => true],
            );

            $warehouse->setAttribute('seed_key', $data['key']);

            return $warehouse;
        });

        $products = collect(range(1, 20))->map(static fn (int $i): Product => Product::query()->firstOrCreate(
            ['sku' => sprintf('SKU-%04d', $i)],
            ['name' => sprintf('Demo Product %02d', $i), 'unit' => 'pcs', 'is_active' => true],
        ));

        $ledger = app(InventoryLedger::class);

        DB::transaction(function () use ($ledger, $warehouses, $products): void {
            foreach ($warehouses as $warehouse) {
                $qty = self::OPENING_STOCK[$warehouse->getAttribute('seed_key')];

                if ($qty === 0) {
                    continue;
                }

                foreach ($products as $index => $product) {
                    $inventory = $ledger->lockPair((int) $product->getKey(), (int) $warehouse->getKey());

                    if ($inventory->on_hand_qty > 0) {
                        continue; // Already seeded — keep this re-runnable.
                    }

                    // The last product is deliberately scarce: a single unit in
                    // the main warehouse. Every "two users race for the last
                    // item" demo and test needs exactly this fixture to exist.
                    $opening = ($index === $products->count() - 1 && $warehouse->code === 'WH-MAIN')
                        ? 1
                        : $qty;

                    $ledger->receipt($inventory, $opening, $product, ['source' => 'opening_balance']);
                }
            }
        });

        $this->seedDemoOrder($products);

        $this->command?->info('Seeded 3 warehouses, 20 products and opening stock via the ledger.');
        $this->command?->info('SKU-0020 in WH-MAIN has exactly 1 unit — use it for the contention demo.');
    }

    /**
     * A draft order with unreserved lines, ready for `demo:scenario` or a manual
     * POST /api/reservations.
     *
     * @param  Collection<int, Product>  $products
     */
    private function seedDemoOrder(Collection $products): void
    {
        if (Order::query()->where('order_number', 'SO-DEMO-0001')->exists()) {
            return;
        }

        $order = Order::query()->create([
            'order_number' => 'SO-DEMO-0001',
            'customer_ref' => 'Demo Customer Ltd',
        ]);

        foreach ($products->take(3) as $product) {
            OrderItem::query()->create([
                'order_id' => $order->getKey(),
                'product_id' => $product->getKey(),
                // Generous on purpose. A reservation is capped by BOTH available
                // stock and the order line's remaining quantity, and a tight
                // demo order would keep hitting the order-line ceiling while you
                // are trying to exercise the stock one. The order-line rule has
                // its own dedicated test rather than being demonstrated by
                // accident here.
                'qty_ordered' => 50,
            ]);
        }
    }
}
