<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseReservationRequest extends FormRequest
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
            // Omitted means "release everything still held" — the common case
            // for a cancelled order line.
            'qty' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['sometimes', 'string', 'max:128'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
