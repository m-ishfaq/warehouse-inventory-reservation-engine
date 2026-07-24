<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Inventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prove that the cached stock position equals the sum of the ledger.
 *
 * This is the command that makes the append-only ledger worth having. Any
 * system can claim its numbers are right; this one can demonstrate it by
 * replaying every movement ever posted and asserting the cache agrees.
 *
 * Exits non-zero on drift, so it can be wired into CI or a monitoring check.
 */
class VerifyInventoryCommand extends Command
{
    protected $signature = 'inventory:verify
                            {--warehouse= : Restrict to one warehouse id}
                            {--product= : Restrict to one product id}
                            {--json : Emit machine-readable output}';

    protected $description = 'Reconcile the inventory cache against the append-only movement ledger';

    public function handle(): int
    {
        $rows = Inventory::query()
            ->when($this->option('warehouse'), fn ($q, $v) => $q->where('warehouse_id', (int) $v))
            ->when($this->option('product'), fn ($q, $v) => $q->where('product_id', (int) $v))
            ->with(['product:id,sku', 'warehouse:id,code'])
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No inventory positions matched.');

            return self::SUCCESS;
        }

        // One grouped query rather than one per row: reconciliation over a real
        // catalogue must not be O(products) round trips.
        $ledger = DB::table('inventory_movements')
            ->select('product_id', 'warehouse_id')
            ->selectRaw('SUM(on_hand_delta) as on_hand_total')
            ->selectRaw('SUM(reserved_delta) as reserved_total')
            ->groupBy('product_id', 'warehouse_id')
            ->get()
            ->keyBy(static fn ($row): string => $row->product_id.':'.$row->warehouse_id);

        $drift = [];
        $table = [];

        foreach ($rows as $row) {
            $key = $row->product_id.':'.$row->warehouse_id;
            $sums = $ledger->get($key);

            $expectedOnHand = (int) ($sums->on_hand_total ?? 0);
            $expectedReserved = (int) ($sums->reserved_total ?? 0);

            $onHandOk = $expectedOnHand === $row->on_hand_qty;
            $reservedOk = $expectedReserved === $row->reserved_qty;

            if (! $onHandOk || ! $reservedOk) {
                $drift[] = [
                    'product_id' => (int) $row->product_id,
                    'warehouse_id' => (int) $row->warehouse_id,
                    'sku' => $row->product?->sku,
                    'warehouse' => $row->warehouse?->code,
                    'cached_on_hand' => $row->on_hand_qty,
                    'ledger_on_hand' => $expectedOnHand,
                    'cached_reserved' => $row->reserved_qty,
                    'ledger_reserved' => $expectedReserved,
                ];
            }

            $table[] = [
                $row->warehouse?->code ?? $row->warehouse_id,
                $row->product?->sku ?? $row->product_id,
                $row->on_hand_qty,
                $expectedOnHand,
                $row->reserved_qty,
                $expectedReserved,
                ($onHandOk && $reservedOk) ? 'OK' : 'DRIFT',
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'checked' => $rows->count(),
                'drift_count' => count($drift),
                'drift' => $drift,
            ], JSON_PRETTY_PRINT));

            return $drift === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Warehouse', 'SKU', 'Cached on-hand', 'Ledger on-hand', 'Cached reserved', 'Ledger reserved', 'Status'],
            $table,
        );

        if ($drift === []) {
            $this->info(sprintf('%d position(s) reconciled — cache and ledger agree exactly.', $rows->count()));

            return self::SUCCESS;
        }

        $this->error(sprintf('%d position(s) have drifted from the ledger.', count($drift)));

        return self::FAILURE;
    }
}
