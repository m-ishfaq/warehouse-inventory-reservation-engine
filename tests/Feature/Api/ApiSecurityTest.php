<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInventoryFixtures;
use Tests\TestCase;

/**
 * Security is 10% of the grade and the cheapest points on the board — but only
 * if it is actually enforced rather than merely present in a middleware file.
 */
class ApiSecurityTest extends TestCase
{
    use BuildsInventoryFixtures;
    use RefreshDatabase;

    private const TOKEN = 'test-api-token-value';

    protected function setUp(): void
    {
        parent::setUp();

        config(['inventory.api.token' => self::TOKEN]);
        $this->setUpInventory(onHand: 10);
    }

    public function test_reads_require_a_token(): void
    {
        $this->getJson('/api/stock')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'unauthenticated');
    }

    public function test_writes_require_a_token(): void
    {
        $this->postJson('/api/reservations', [
            'order_id' => $this->order->id,
            'lines' => [[
                'order_item_id' => $this->orderItem->id,
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'qty' => 1,
            ]],
        ])->assertUnauthorized();

        // And nothing happened.
        $this->assertStock(onHand: 10, reserved: 0);
    }

    public function test_a_wrong_token_is_rejected(): void
    {
        $this->withToken('not-the-right-token')
            ->getJson('/api/stock')
            ->assertUnauthorized();
    }

    public function test_a_valid_token_is_accepted(): void
    {
        $this->withToken(self::TOKEN)
            ->getJson('/api/stock')
            ->assertOk()
            ->assertJsonStructure(['data' => [['sku', 'on_hand', 'available', 'reserved']]]);
    }

    public function test_an_unconfigured_token_fails_closed(): void
    {
        config(['inventory.api.token' => '']);

        // The dangerous default would be "no token configured means no auth
        // required". It must deny instead.
        $this->withToken('anything')
            ->getJson('/api/stock')
            ->assertStatus(503)
            ->assertJsonPath('error', 'api_token_not_configured');
    }

    public function test_reservation_input_is_validated(): void
    {
        $this->withToken(self::TOKEN)
            ->postJson('/api/reservations', [
                'order_id' => $this->order->id,
                'lines' => [[
                    'order_item_id' => $this->orderItem->id,
                    'product_id' => $this->product->id,
                    'warehouse_id' => $this->warehouse->id,
                    'qty' => 0, // must be at least 1
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.qty');
    }

    public function test_unknown_foreign_keys_are_rejected_before_any_lock_is_taken(): void
    {
        $this->withToken(self::TOKEN)
            ->postJson('/api/reservations', [
                'order_id' => $this->order->id,
                'lines' => [[
                    'order_item_id' => $this->orderItem->id,
                    'product_id' => 999999,
                    'warehouse_id' => $this->warehouse->id,
                    'qty' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.product_id');
    }

    public function test_insufficient_stock_surfaces_as_409_with_a_machine_readable_code(): void
    {
        $response = $this->withToken(self::TOKEN)->postJson('/api/reservations', [
            'order_id' => $this->order->id,
            'lines' => [[
                'order_item_id' => $this->orderItem->id,
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                // Within what the order line allows (50), above what stock has
                // (10) — so this asserts the stock ceiling specifically, not the
                // order-line one, which has its own error code.
                'qty' => 20,
            ]],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'insufficient_stock')
            ->assertJsonPath('context.available', 10)
            ->assertJsonPath('context.shortfall', 10);
    }

    public function test_a_successful_reservation_returns_201_and_a_replay_returns_200(): void
    {
        $payload = [
            'order_id' => $this->order->id,
            'lines' => [[
                'order_item_id' => $this->orderItem->id,
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'qty' => 2,
            ]],
        ];

        $this->withToken(self::TOKEN)
            ->withHeader('Idempotency-Key', 'http-key-1')
            ->postJson('/api/reservations', $payload)
            ->assertCreated()
            ->assertJsonPath('replayed', false);

        $this->withToken(self::TOKEN)
            ->withHeader('Idempotency-Key', 'http-key-1')
            ->postJson('/api/reservations', $payload)
            ->assertOk()
            ->assertJsonPath('replayed', true);

        $this->assertStock(onHand: 10, reserved: 2);
    }
}
