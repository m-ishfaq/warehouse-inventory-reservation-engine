<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Inventory\DTOs\ReservationLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation is the outermost of the three defences against bad reservations.
 * It rejects malformed input cheaply, before any lock is taken — but it
 * deliberately does NOT check availability. Availability checked here would be
 * stale by the time the transaction opens, which is exactly the bug that lets
 * two users reserve the last item. That check belongs inside the lock.
 */
class ReserveStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Enforced by the AuthenticateApiToken middleware.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer', Rule::exists('orders', 'id')],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.order_item_id' => ['required', 'integer', Rule::exists('order_items', 'id')],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'lines.*.warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],

            'allow_partial' => ['sometimes', 'boolean'],
            'ttl_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
        ];
    }

    /**
     * @return list<ReservationLine>
     */
    public function lines(): array
    {
        /** @var list<array{order_item_id:int, product_id:int, warehouse_id:int, qty:int}> $lines */
        $lines = $this->validated('lines');

        return array_map(ReservationLine::fromArray(...), $lines);
    }

    /**
     * The idempotency key travels as a header, matching the convention used by
     * Stripe and friends — clients already know this pattern.
     */
    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
