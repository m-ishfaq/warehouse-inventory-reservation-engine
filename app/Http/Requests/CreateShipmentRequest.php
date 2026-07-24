<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer', Rule::exists('orders', 'id')],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],

            // reservation id => qty. Requesting fewer units than the reservation
            // holds is how a partial shipment is expressed.
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.reservation_id' => ['required', 'integer', Rule::exists('reservations', 'id')],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],

            'dispatch_now' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function reservationQuantities(): array
    {
        $quantities = [];

        /** @var list<array{reservation_id:int, qty:int}> $lines */
        $lines = $this->validated('lines');

        foreach ($lines as $line) {
            // Sum rather than overwrite: a client listing the same reservation
            // twice means "ship this much in total", not "ignore the first".
            $id = (int) $line['reservation_id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) $line['qty'];
        }

        return $quantities;
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
