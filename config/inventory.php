<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Reservation behaviour
    |--------------------------------------------------------------------------
    |
    | The quest deliberately leaves reservation expiry undefined. We support it:
    | a reservation holds stock for a bounded window, after which a scheduled
    | sweep releases it. See docs/ARCHITECTURE.md for the trade-off discussion.
    |
    */

    'reservation' => [
        'default_ttl_minutes' => (int) env('INVENTORY_RESERVATION_TTL', 30),
        'expiry_batch_size' => (int) env('INVENTORY_EXPIRY_BATCH', 500),
        'allow_partial_by_default' => (bool) env('INVENTORY_ALLOW_PARTIAL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrency
    |--------------------------------------------------------------------------
    |
    | Every mutating operation runs inside a transaction that takes pessimistic
    | row locks on the affected inventory rows. Deadlocks are theoretically
    | impossible because locks are acquired in ascending inventory.id order, but
    | we still retry a small number of times to survive lock-wait timeouts.
    |
    */

    'locking' => [
        'transaction_attempts' => (int) env('INVENTORY_TX_ATTEMPTS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Completed idempotency records are retained so that late replays return the
    | original response rather than re-executing the operation.
    |
    */

    'idempotency' => [
        'retention_hours' => (int) env('INVENTORY_IDEMPOTENCY_RETENTION', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | API authentication
    |--------------------------------------------------------------------------
    |
    | A single shared bearer token guards the mutating endpoints. This is a
    | deliberate scope decision, not an oversight: per-user revocable tokens
    | belong to Sanctum/Passport, and swapping this middleware for 'auth:sanctum'
    | is a one-line change. See docs/ARCHITECTURE.md.
    |
    */

    'api' => [
        'token' => env('INVENTORY_API_TOKEN', ''),
        'rate_limit_per_minute' => (int) env('INVENTORY_API_RATE_LIMIT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shipping provider
    |--------------------------------------------------------------------------
    |
    | 'forced_outcome' pins the mock provider to a single behaviour, which is how
    | the demo:scenario command and the test suite drive deterministic failures.
    | Leave it null for the randomised behaviour the quest asks for.
    |
    */

    'shipping' => [
        'driver' => env('SHIPPING_DRIVER', 'mock'),

        'webhook' => [
            'secret' => env('SHIPPING_WEBHOOK_SECRET', ''),
            'tolerance_seconds' => (int) env('SHIPPING_WEBHOOK_TOLERANCE', 300),
        ],

        'mock' => [
            'forced_outcome' => env('SHIPPING_MOCK_OUTCOME'),

            // Relative weights for randomized outcomes.
            'weights' => [
                'success' => (int) env('SHIPPING_MOCK_W_SUCCESS', 55),
                'permanent_failure' => (int) env('SHIPPING_MOCK_W_FAIL', 10),
                'timeout' => (int) env('SHIPPING_MOCK_W_TIMEOUT', 15),
                'duplicate_confirmation' => (int) env('SHIPPING_MOCK_W_DUPLICATE', 10),
                'delayed_success' => (int) env('SHIPPING_MOCK_W_DELAYED', 10),
            ],

            'delay_seconds' => (int) env('SHIPPING_MOCK_DELAY', 2),
        ],
    ],

];
