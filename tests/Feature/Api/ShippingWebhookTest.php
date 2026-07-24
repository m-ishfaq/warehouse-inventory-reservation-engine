<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Reservation;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInventoryFixtures;
use Tests\TestCase;

/**
 * A webhook that deducts inventory is an unauthenticated write endpoint unless
 * it is signed. These tests are the proof that it is.
 */
class ShippingWebhookTest extends TestCase
{
    use BuildsInventoryFixtures;
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    private Reservation $reservation;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        config(['inventory.shipping.webhook.secret' => self::SECRET]);

        $this->setUpInventory(onHand: 10);
        $this->reservation = $this->reserve(4);
        $this->shipment = $this->dispatchedShipment($this->reservation, 4);
    }

    public function test_an_unsigned_callback_is_rejected(): void
    {
        $this->postJson('/api/webhooks/shipping/confirm', $this->payload())
            ->assertUnauthorized()
            ->assertJsonPath('error', 'missing_signature');

        $this->assertStock(onHand: 10, reserved: 4);
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $this->send($this->payload(), signature: str_repeat('a', 64))
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_signature');

        $this->assertStock(onHand: 10, reserved: 4);
    }

    public function test_a_stale_signature_is_rejected(): void
    {
        // A captured, genuinely-signed request replayed hours later must fail.
        $this->send($this->payload(), timestamp: time() - 3600)
            ->assertUnauthorized()
            ->assertJsonPath('error', 'signature_expired');

        $this->assertStock(onHand: 10, reserved: 4);
    }

    public function test_a_signature_over_a_different_body_is_rejected(): void
    {
        $signed = $this->payload();
        $tampered = $this->payload();
        $tampered['lines'][0]['qty'] = 4000;

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.json_encode($signed), self::SECRET);

        $this->call(
            'POST',
            '/api/webhooks/shipping/confirm',
            [],
            [],
            [],
            $this->headers($timestamp, $signature),
            (string) json_encode($tampered),
        )->assertUnauthorized();
    }

    public function test_a_correctly_signed_callback_settles_the_shipment(): void
    {
        $this->send($this->payload())
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertStock(onHand: 6, reserved: 0);
    }

    public function test_a_duplicate_callback_returns_success_without_deducting_again(): void
    {
        $this->send($this->payload())->assertOk();

        // 200, not 4xx: a carrier that receives an error treats delivery as
        // failed and retries forever.
        //
        // The body replays the original response rather than reporting zero —
        // a retrying carrier must receive the same answer it would have got the
        // first time, not a different one that looks like a no-op.
        $this->send($this->payload())
            ->assertOk()
            ->assertJsonPath('status', 'duplicate_ignored')
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.shipped', 4);

        // What proves the duplicate was harmless: stock moved once.
        $this->assertStock(onHand: 6, reserved: 0);
        $this->assertDatabaseCount('processed_webhooks', 1);
    }

    public function test_an_unmatched_provider_reference_is_accepted_not_rejected(): void
    {
        $payload = $this->payload();
        $payload['provider_ref'] = 'MOCK-DOES-NOT-EXIST';

        // 202: the carrier may confirm before our dispatch transaction commits.
        // Telling it "not found" would make it give up on an event we want.
        $this->send($payload)
            ->assertStatus(202)
            ->assertJsonPath('status', 'accepted_unmatched');
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'event_id' => 'evt_webhook_test_001',
            'provider_ref' => $this->shipment->provider_ref,
            'lines' => [
                ['reservation_id' => $this->reservation->id, 'qty' => 4],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload, ?int $timestamp = null, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $timestamp ??= time();
        $body = (string) json_encode($payload);
        $signature ??= hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        return $this->call(
            'POST',
            '/api/webhooks/shipping/confirm',
            [],
            [],
            [],
            $this->headers($timestamp, $signature),
            $body,
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(int $timestamp, string $signature): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SHIPPING_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SHIPPING_SIGNATURE' => $signature,
        ];
    }
}
