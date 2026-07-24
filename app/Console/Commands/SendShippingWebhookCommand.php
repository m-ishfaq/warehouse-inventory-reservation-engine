<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Shipment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Send a correctly-signed carrier callback at the running application.
 *
 * Exists because the webhook is the one endpoint you cannot exercise by hand:
 * it authenticates with an HMAC-SHA256 over the raw request body, and computing
 * that in a shell is painful enough that people skip testing it — which is
 * exactly the endpoint you least want untested, since it deducts inventory.
 *
 * Run it twice with the same --event to watch duplicate handling: the first call
 * settles the shipment, the second returns 200 with status "duplicate_ignored"
 * and moves no stock.
 *
 * Dev tooling. Refuses to run in production.
 */
class SendShippingWebhookCommand extends Command
{
    protected $hidden = true;

    protected $signature = 'demo:webhook
                            {shipment : Shipment id to confirm}
                            {--qty=* : Per-line override as reservationId:qty (repeatable)}
                            {--event= : Reuse a specific event id to simulate a duplicate}
                            {--url= : Base URL of the running app (default APP_URL)}
                            {--tamper : Send a deliberately invalid signature}
                            {--stale : Backdate the timestamp past the tolerance window}';

    protected $description = 'Send a signed shipping confirmation webhook to the running application';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:webhook is development tooling and will not run in production.');

            return self::FAILURE;
        }

        $shipment = Shipment::query()->with('items')->find((int) $this->argument('shipment'));

        if ($shipment === null) {
            $this->error('No such shipment.');

            return self::FAILURE;
        }

        if ($shipment->provider_ref === null) {
            $this->error('That shipment has no provider_ref yet — dispatch it first:');
            $this->line('  php artisan shipments:process --shipment='.$shipment->getKey().' --sync');

            return self::FAILURE;
        }

        $payload = [
            'event_id' => $this->option('event') ?: 'evt_'.Str::lower(Str::random(20)),
            'provider_ref' => $shipment->provider_ref,
            'lines' => $this->lines($shipment),
        ];

        $body = (string) json_encode($payload);
        $timestamp = $this->option('stale') ? time() - 86400 : time();

        $signature = $this->option('tamper')
            ? str_repeat('0', 64)
            : hash_hmac('sha256', $timestamp.'.'.$body, (string) config('inventory.shipping.webhook.secret'));

        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/').'/api/webhooks/shipping/confirm';

        $this->line('POST '.$url);
        $this->line('  event_id    '.$payload['event_id']);
        $this->line('  provider_ref '.$payload['provider_ref']);
        $this->line('  lines        '.json_encode($payload['lines']));
        $this->newLine();

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Shipping-Timestamp' => (string) $timestamp,
            'X-Shipping-Signature' => $signature,
        ])->withBody($body, 'application/json')->post($url);

        $this->line(sprintf(
            '<fg=%s>HTTP %d</>',
            $response->successful() ? 'green' : 'red',
            $response->status(),
        ));
        $this->line($response->body());

        $this->newLine();
        $this->line('<fg=gray>Re-run with --event='.$payload['event_id'].' to send the duplicate.</>');

        return self::SUCCESS;
    }

    /**
     * Default to confirming everything the shipment asked for, unless the caller
     * overrides a line to simulate the carrier shipping short.
     *
     * @return list<array{reservation_id:int, qty:int}>
     */
    private function lines(Shipment $shipment): array
    {
        $overrides = [];

        foreach ((array) $this->option('qty') as $pair) {
            [$reservationId, $qty] = array_pad(explode(':', (string) $pair, 2), 2, null);
            $overrides[(int) $reservationId] = (int) $qty;
        }

        $lines = [];

        foreach ($shipment->items as $item) {
            $lines[] = [
                'reservation_id' => (int) $item->reservation_id,
                'qty' => $overrides[(int) $item->reservation_id] ?? ($item->qty_requested - $item->qty_shipped),
            ];
        }

        return $lines;
    }
}
