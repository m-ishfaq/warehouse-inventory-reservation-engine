<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferStockRequest extends FormRequest
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
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'from_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id', Rule::exists('warehouses', 'id')],
            'qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['sometimes', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_warehouse_id.different' => 'Source and destination warehouse must differ.',
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
