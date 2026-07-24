<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\IdempotentResult;
use App\Domain\Inventory\Enums\IdempotencyStatus;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Exactly-once semantics on top of an at-least-once world.
 *
 * The entire mechanism is the unique index on (scope, key). No application
 * locks, no Redis, no second source of truth that can drift from the database.
 *
 * How each case resolves:
 *
 *   Sequential replay    The row is already `completed`, so the stored response
 *                        is returned and the operation never runs again.
 *
 *   Concurrent duplicate The second INSERT blocks on the unique index until the
 *                        first transaction commits, then fails as a duplicate.
 *                        We re-read the winner's row — with a locking read, so
 *                        REPEATABLE READ cannot serve us a stale snapshot — and
 *                        return its stored response.
 *
 *   Crash mid-operation  The transaction rolls back and takes the claim row with
 *                        it, leaving nothing behind. The retry runs for real.
 *
 * That covers four of the quest's required failure scenarios with one table:
 * duplicate command, duplicate job execution, duplicate webhook, worker retry.
 */
final class IdempotencyGuard
{
    /** MySQL SQLSTATE / driver code for a unique constraint violation. */
    private const DUPLICATE_ENTRY_CODE = 1062;

    /**
     * Run $operation at most once for the given (scope, key).
     *
     * $operation must return a JSON-serialisable array. That constraint is
     * deliberate: whatever it returns has to survive being stored and replayed
     * to a caller who arrives ten minutes later, so it cannot be an Eloquent
     * model graph. Callers rehydrate from ids.
     *
     * @param  Closure(): array<string, mixed>  $operation
     */
    public function execute(string $scope, ?string $key, Closure $operation): IdempotentResult
    {
        $attempts = (int) config('inventory.locking.transaction_attempts', 3);

        // No key supplied: caller has opted out of replay protection. Legitimate
        // for internal calls already guarded by a state transition. The operation
        // still runs in a transaction — atomicity is not negotiable, only the
        // replay bookkeeping is.
        if ($key === null || $key === '') {
            return new IdempotentResult(false, DB::transaction($operation, $attempts));
        }

        // Fast path — a replay that arrives long after the original finished.
        $existing = IdempotencyKey::query()
            ->where('scope', $scope)
            ->where('key', $key)
            ->first();

        if ($existing?->isCompleted()) {
            return new IdempotentResult(true, $existing->response ?? []);
        }

        return DB::transaction(function () use ($scope, $key, $operation): IdempotentResult {
            $record = $this->claim($scope, $key);

            // claim() returns null when another transaction won the race and has
            // already completed the work — its stored response is authoritative.
            if ($record === null) {
                return new IdempotentResult(true, $this->completedResponse($scope, $key));
            }

            $payload = $operation();

            $record->forceFill([
                'status' => IdempotencyStatus::Completed,
                'response' => $payload,
                'completed_at' => now(),
            ])->save();

            return new IdempotentResult(false, $payload);
        }, $attempts);
    }

    /**
     * Attempt to claim (scope, key).
     *
     * Returns the claim row on success, or null when somebody else already
     * claimed AND completed it. Throws when somebody else claimed it and is
     * still working — the caller should back off and retry the same key.
     */
    private function claim(string $scope, string $key): ?IdempotencyKey
    {
        try {
            return IdempotencyKey::create([
                'scope' => $scope,
                'key' => $key,
                'status' => IdempotencyStatus::InProgress,
                'locked_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateEntry($exception)) {
                throw $exception;
            }
        }

        // A locking read, not a plain one. Under REPEATABLE READ a plain SELECT
        // would be served from this transaction's snapshot, which was taken
        // before the winner committed — we would see nothing and wrongly report
        // a conflict. lockForUpdate always reads the latest committed row.
        $winner = IdempotencyKey::query()
            ->where('scope', $scope)
            ->where('key', $key)
            ->lockForUpdate()
            ->first();

        if ($winner?->isCompleted()) {
            return null;
        }

        throw IdempotencyConflictException::inFlight($scope, $key);
    }

    /**
     * @return array<string, mixed>
     */
    private function completedResponse(string $scope, string $key): array
    {
        // Fetch the model rather than value('response'): value() bypasses the
        // array cast and would hand back a raw JSON string.
        $record = IdempotencyKey::query()
            ->where('scope', $scope)
            ->where('key', $key)
            ->first();

        return $record?->response ?? [];
    }

    private function isDuplicateEntry(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === self::DUPLICATE_ENTRY_CODE
            || $exception->getCode() === '23000';
    }

    /**
     * Housekeeping for the retention window configured in config/inventory.php.
     */
    public function pruneExpired(): int
    {
        $cutoff = now()->subHours((int) config('inventory.idempotency.retention_hours', 72));

        return IdempotencyKey::query()
            ->where('status', IdempotencyStatus::Completed)
            ->where('completed_at', '<', $cutoff)
            ->delete();
    }
}
