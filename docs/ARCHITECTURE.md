# Architecture

## 1. The problem, stated precisely

An ERP cannot deduct stock when an order is placed. Goods move through stages —
available, reserved, picked, packed, shipped, delivered — and between those
stages the world misbehaves: users race, operators cancel, jobs retry, carriers
time out and then send the same confirmation twice.

Two invariants must survive all of it:

> **I1.** Inventory must never go negative.
> **I2.** The same physical unit must never be promised twice.

Everything below is in service of those two sentences.

---

## 2. Domain model

```
Product ─┐
         ├── Inventory (product × warehouse)  ← materialised cache
Warehouse┘        │
                  │  every change is justified by
                  ▼
         InventoryMovement  ← append-only ledger, SOURCE OF TRUTH

Order ── OrderItem ── Reservation ── ReservationEvent   (append-only history)
                           │
                           └── ShipmentItem ── Shipment
```

Supporting tables: `idempotency_keys` (exactly-once), `processed_webhooks`
(carrier replay protection).

### Why the ledger is the source of truth

The obvious design stores quantities on a row and updates them. It works until
the first time something goes wrong, and then it cannot answer the only question
that matters: *why is this number what it is?*

Here, `inventory_movements` is append-only — the model throws on `update()` and
`delete()`, so immutability is structural rather than a convention. Every
quantity in the system is reconstructible by replaying it:

```sql
SUM(on_hand_delta)  = inventory.on_hand_qty
SUM(reserved_delta) = inventory.reserved_qty
```

`php artisan inventory:verify` asserts exactly that and exits non-zero on drift,
so it can run in CI or as a monitoring check.

### Why keep a cache at all

Replaying millions of movements to answer "what is available right now" is not
viable, and the reservation hot path needs a *single row to lock*. The
`inventory` table gives both: O(1) availability reads, and one lockable row per
(product, warehouse). The cost — two things that could drift — is paid down by
writing both in the same transaction and verifying continuously.

### Two deltas, not one

Movements record `on_hand_delta` and `reserved_delta` separately because several
types touch both buckets at once. A shipment lowers on-hand *and* releases the
reservation that authorised it. Storing one signed `qty_delta` plus a type would
force reconciliation to interpret business meaning; storing both deltas makes it
a plain `SUM` per column.

---

## 3. Reservation lifecycle

```
                  ┌─────────────────────────┐
                  ▼                         │ (further partial shipments)
  ──► active ──► partially_consumed ────────┘
        │  │            │
        │  │            ├──► consumed   (fully shipped)
        │  │            ├──► released   (remainder handed back)
        │  │            └──► expired    (TTL swept the remainder)
        │  ├──► consumed
        │  ├──► released
        │  └──► expired
```

`consumed`, `released` and `expired` are terminal. The transition table lives in
`ReservationStatus`, not in the service, so an illegal transition is impossible
to write anywhere in the codebase — including from a controller or command that
does not exist yet.

Quantities: `qty` never changes; `qty_consumed` and `qty_released` grow. What the
reservation still holds is `qty − qty_consumed − qty_released`, and that single
expression is what a release, an expiry, and a shipment all clamp against.

**Detail worth noticing:** a reservation that ships 4 of 10 and then releases the
remaining 6 ends as `consumed`, not `released` — stock genuinely moved, and the
audit trail should say so.

### Two ceilings, not one

A reservation is bounded by two unrelated quantities, and both are checked under
their own lock inside the same transaction:

```
available   = on_hand − reserved                              (the warehouse)
outstanding = ordered − reserved − shipped − cancelled        (the order line)
granted     = min(requested, available, outstanding)
```

Only checking stock is the obvious mistake, and it is not harmless: it lets a
caller reserve 100 units against a line that ordered 5. The
`chk_order_items_allocation_within_ordered` constraint does catch it — but a
constraint reached is a bug surfacing as a 500 with SQL in the body, not a
validation a client can act on.

The two failures get **separate error codes on purpose**.
`insufficient_stock` means the warehouse does not have it, which may resolve on
the next goods receipt. `exceeds_ordered_quantity` means the customer never asked
for it, which will never resolve. One code for both would tell a client to keep
retrying a request that can only ever fail.

**Lock ordering matters here too.** Order lines are locked *after* inventory,
never before, matching `consume()` and `settle()` which already touch inventory
first. Reverse it in one path and a reservation racing a shipment on the same
line deadlocks — the same lesson as the `INSERT IGNORE` bug below: an ordering
rule only helps if every path obeys it.

One consequence worth stating: because the order line is locked, its
`qty_reserved` is updated by read-modify-write rather than a SQL increment. A
bare increment would leave the model stale in memory, so a second line against
the same order line would re-read the original outstanding figure and both would
allocate the same units.

---

## 4. Concurrency

### The choice

**Pessimistic row locks (`SELECT ... FOR UPDATE`), acquired in ascending
`inventory.id` order, always.**

The ordering is the whole deadlock story. Two concurrent multi-line orders
touching the same products cannot hold locks in opposite order and wait on each
other, because both sort before locking. `InventoryLedger::lockPairs()` is the
only way to obtain a lockable row, so there is no path that bypasses the
ordering.

### Why not the alternatives

| Approach | Why not |
|---|---|
| **Optimistic (version column) only** | Correct, but degrades badly exactly where it matters. On the last unit of a hot SKU, every concurrent writer fails the version check and retries, producing a retry storm at peak load. A `version` column exists here for auditing, not as the primary mechanism. |
| **Redis / advisory locks** | Introduces a second source of truth that can drift from the database. If Redis and MySQL disagree about who holds the lock, the invariant is gone and nothing in the database can detect it. The DB already has a perfectly good lock manager. |
| **Application-level mutex / `serializable`** | Serialising the whole table throws away all concurrency to solve a per-row problem. |
| **Optimistic + pessimistic hybrid** | Considered. Rejected as unnecessary complexity at this scale — a second mechanism to keep correct, protecting against a failure the first one already handles. |

### The critical detail: availability is re-read inside the lock

Two users both see "1 available" before their transactions start. Validation at
the HTTP boundary would let both pass. The check that matters happens after the
lock is held, against the freshly-read row:

```php
$available = $inventory->availableQty();   // read under FOR UPDATE
if ($available < $line->qty) { throw InsufficientStockException; }
```

The loser blocks on the lock, then re-reads and sees 0. This is why
`ReserveStockRequest` deliberately does *not* validate availability — a stale
check that "passes" is worse than no check, because it invites trust.

### Three layers against overselling

1. **The row lock** — serialises writers to one stock position.
2. **The in-transaction re-check** — rejects the loser with a useful error
   carrying `requested`, `available`, `shortfall`.
3. **DB `CHECK` constraints** — `reserved_qty <= on_hand_qty`,
   `picked_qty <= reserved_qty`, `packed_qty <= picked_qty`, plus unsigned
   columns. A future bug, a bad migration, or somebody editing rows by hand
   still cannot produce an impossible state.

Layer 3 is what turns "probably correct" into "provably correct". Layers 1–2
produce good errors; layer 3 makes the bad state unrepresentable.

### Locking is enforced, not trusted

`InventoryLedger` throws a `LogicException` if called outside a transaction.
Without it, a caller who forgot the transaction would appear to work in testing
and corrupt stock the first time an operation failed halfway.

### A real deadlock the concurrency test caught

Ordered lock acquisition is necessary but **not sufficient** — it only governs
locks taken at the point of acquisition. Any lock taken *earlier in the same
transaction* sits outside the ordering.

The first version of `lockPairs()` called `INSERT IGNORE` unconditionally to
create missing stock positions, before taking the ordered locks. InnoDB's
duplicate-key handling for `INSERT IGNORE` acquires a **shared lock on the row it
collided with**. Under eight concurrent reservations for one SKU, every
transaction took S on the same row, then every transaction asked for X via
`SELECT ... FOR UPDATE` — each waiting for all the others to release S before it
could upgrade. Textbook S→X upgrade deadlock, invisible in the SQL, and entirely
outside the reach of the id-ordering guarantee.

The fix is to read first with a plain non-locking `SELECT` and issue the insert
only for pairs that genuinely do not exist. The hot path now takes no lock before
the ordered acquisition, so it cannot deadlock. Only the first-ever reservation
for a (product, warehouse) pair reaches the insert, and the surrounding
transaction retry absorbs a collision there.

Worth recording for two reasons. First, it is the failure mode most likely to
recur if someone later adds a write to the front of this method. Second, it was
found by the assertion in the concurrency test that requires every *loser* to
have failed with `insufficient_stock` specifically. A weaker test asserting only
"one succeeded" would have passed — the deadlocked transactions still lost, and
stock was still never oversold. The bug would have shipped as intermittent 500s
under load.

---

## 5. Idempotency: at-least-once → exactly-once

Queues redeliver. Carriers resend. Users double-click. Networks time out after
the server already committed. Every one of those is an at-least-once delivery,
and the engine must be exactly-once on top of it.

The entire mechanism is **a unique index on `(scope, key)`**. No application
locks, no Redis, no second source of truth.

| Situation | What happens |
|---|---|
| **Sequential replay** | Row is already `completed`; the stored response is returned and the operation never runs. |
| **Concurrent duplicate** | The second `INSERT` blocks on the unique index until the first transaction commits, then fails as a duplicate. We re-read the winner's row and return its response. |
| **Crash mid-operation** | The transaction rolls back and takes the claim row with it. The retry runs for real. |

One subtlety that is easy to get wrong: the re-read after a duplicate-key error
uses `lockForUpdate()`. Under `REPEATABLE READ` a plain `SELECT` is served from
the snapshot taken *before* the winner committed — we would see nothing and
wrongly report a conflict. A locking read always sees the latest committed row.

This one table covers four of the quest's required scenarios: duplicate command,
duplicate job execution, duplicate webhook, and worker retry.

**Deliberate scope limit:** operations without a key are *not* deduplicated.
Omitting the key is opting out, and the API documents `Idempotency-Key` as
strongly recommended rather than silently inventing one — a server-generated key
would deduplicate requests the client considers distinct.

---

## 6. Shipments: the single most important decision

> **Inventory is deducted on CONFIRMATION, never on dispatch.**

Deducting at dispatch is simpler and more common. It also makes a carrier
timeout unrecoverable: stock has been removed for goods that may never have left
the building, and there is no way to distinguish "shipped" from "unknown".

Deferring the deduction means the dangerous state (`dispatched`) costs a *held
reservation* and nothing else. Consequences:

- A timeout parks the shipment in `dispatched`, keeps the reservation, and throws
  so the queue retries. Nothing is lost, nothing is double-counted.
- A confirmation arriving an hour later still settles correctly, matched by
  `provider_ref`.
- A permanent carrier rejection does **not** release the reservation. The goods
  are still promised to that order; whether to free them is a commercial
  decision, not something a failed API call should make on the operator's behalf.

### Three independent guards on confirmation

1. `processed_webhooks.provider_event_id` unique — the same event is recorded once.
2. The idempotency table — the same confirm operation replays.
3. The shipment state machine — a settled shipment refuses to settle again.

Any one would suffice. Having all three means misconfiguring or removing one does
not cost correctness.

### Claiming a shipment for dispatch

A conditional `UPDATE ... WHERE status IN ('pending','failed')` — the WHERE
clause *is* the lock. Two workers pulling the same job: exactly one matches a
row, the other sees zero affected and backs off. No advisory lock, no race.

---

## 7. Database design notes

- **`inventory` is unique on `(product_id, warehouse_id)`** — one lockable row
  per stock position, which is what makes the lock granularity right.
- **Rows are created with `insertOrIgnore`**, not `firstOrCreate`: two concurrent
  first-time reservations for a new product would otherwise race the unique index.
- **`shipments.provider_ref` is unique** — a retried dispatch cannot create a
  second shipment against the same consignment.
- **`reservations` is indexed on `(status, expires_at)`** for the sweep and
  `(product_id, warehouse_id, status)` for stock queries.
- **`inventory_movements` stores `on_hand_after` / `reserved_after`** so a
  history view renders without re-summing the ledger.
- **Order status is derived from its lines**, never mutated independently, so it
  cannot drift from what it summarises.

---

## 8. Security

| Concern | Handling |
|---|---|
| **API auth** | Bearer token, compared with `hash_equals` (constant time — `===` short-circuits on the first differing byte and leaks the token one character at a time). Fails **closed**: an unconfigured token denies everything rather than allowing everything. |
| **Webhook auth** | HMAC-SHA256 over `timestamp + "." + raw body`. The raw body matters — re-encoding parsed JSON changes byte order and breaks legitimate callers. |
| **Webhook replay** | Timestamp tolerance window (300s) stops captured requests being replayed forever; `processed_webhooks` stops processed events being reprocessed. |
| **Rate limiting** | Reservation creation is throttled harder than reads. An unthrottled reserve endpoint is a **denial-of-service against stock itself** — an attacker can lock the entire catalogue without buying anything. This is a business control, not just infrastructure. |
| **Mass assignment** | Explicit `$fillable` on every model, plus `preventSilentlyDiscardingAttributes` so a forgotten one fails loudly. |
| **SQL injection** | Eloquent and bindings throughout; the few `DB::raw` uses interpolate only integers that have already passed integer validation. |
| **XSS** | Blade escapes by default; the dashboard's jQuery escapes every server value before it touches the DOM. No `{!! !!}` anywhere. |
| **CSRF** | Web routes inherit Laravel's CSRF middleware; the dashboard's JSON endpoints are GETs. |
| **Error leakage** | Domain exceptions expose a stable code and safe context, never internals. |

### Known gap, stated openly

Auth is a **single shared token**, not per-user, revocable, or scoped. Sanctum or
Passport is the production answer and the swap is a one-line middleware change.
This was a deliberate scope decision under a one-day deadline, not an oversight —
recording it here is more useful than pretending otherwise.

---

## 9. Testing strategy

| Layer | What it proves |
|---|---|
| **Unit** (no DB) | State machines reject illegal transitions; DTO invariants hold. Fast, and they fail with precise messages. |
| **Feature** | Each required failure scenario end-to-end against real MySQL. |
| **Concurrency** (separate suite) | Real parallel OS processes racing the same row. |
| **Reconciliation** | After every scenario, `SUM(deltas) == cache`. |

Two deliberate choices:

**Fixtures post stock through the ledger, never by writing quantities directly.**
If tests seeded the cache straight, they would reconcile against data that never
went through the code under test — the invariant tests would be tautologies.

**The concurrency suite shells out.** There is no honest way to simulate two
racing connections inside one single-threaded PHP process: the loser's
`FOR UPDATE` blocks, and the test deadlocks waiting for a commit it is itself
blocking. Child processes synchronise on a shared start timestamp so they
genuinely collide rather than staggering by framework boot time.

The strongest assertion in the suite is not "one succeeded" — it is that every
*loser* failed with `insufficient_stock` specifically. A deadlock or lock-wait
timeout would also look like failure, and would mean the ordered-lock guarantee
is not actually holding. That assertion is what caught the `INSERT IGNORE`
deadlock described in §4.

### One trap worth knowing about

`LedgerTransactionGuardTest` uses `DatabaseTruncation` rather than
`RefreshDatabase`, because the guard it tests — "the ledger refuses to post
outside a transaction" — cannot be observed inside `RefreshDatabase`'s
uncommitted wrapping transaction, where `transactionLevel()` is never zero. That
class therefore *commits* its fixtures, and truncates at setUp rather than
tearDown, so its last test's data survives into later classes.

Consequence: assertions elsewhere must be scoped to their own fixtures. A global
`COUNT(*)` passes in isolation and fails in a full run depending purely on
execution order — the worst failure mode a test can have, because the natural
reaction is to distrust the suite rather than the assertion. Delta-based
assertions (snapshot before, compare after) are immune and are used where a count
is genuinely the right check.

---

## 10. Scaling

Current shape handles a single-database ERP comfortably. At millions of
transactions:

**Read path** — `inventory` is already O(1) per position. Add read replicas for
the dashboard and reporting; the write path must stay on the primary because it
needs `FOR UPDATE`.

**Write path** — contention is per (product, warehouse) row, so throughput scales
with catalogue breadth, not total volume. A single viral SKU is the bottleneck.
Mitigations, in order of preference:
1. Batch reservations per request (already supported — one lock acquisition per
   order, not per line).
2. Shard hot SKUs into sub-buckets (`product × warehouse × bucket`) and reserve
   from a random bucket, trading exact availability reporting for lock spread.
3. Queue reservations for the hottest SKUs and process them serially — turns
   contention into latency, which is usually the better failure mode.

**Ledger growth** — `inventory_movements` grows monotonically. Partition by
`occurred_at` (monthly), archive cold partitions, and make `inventory:verify`
incremental: checkpoint balances periodically and verify only the tail.

**Idempotency table** — already has a retention window and `pruneExpired()`.

**Queue** — Redis over the database driver; separate queues per priority so a
backlog of expiry sweeps never delays shipment dispatch.

---

## 11. Trade-offs, honestly

| Chose | Over | Because | Cost |
|---|---|---|---|
| Ledger + cache | Quantities on a row | Auditability, provable correctness | Two things that could drift; paid down by same-transaction writes and `inventory:verify` |
| Pessimistic locking | Optimistic | No retry storms on hot SKUs | Lower throughput on contended rows |
| Deduct on confirm | Deduct on dispatch | Timeouts become recoverable | A stuck shipment holds a reservation until reconciled |
| All-or-nothing reservations | Auto-partial | Silent under-reservation ships wrong quantities | Callers must opt into partial mode explicitly |
| Reserved stock is pinned | Re-point reservations on transfer | Preserves carrier routing and printed pick lists | Transfers are refused more often |
| Shared API token | Sanctum | Zero dependencies, one-day deadline | Not per-user or revocable — documented, not hidden |
| Derived order status | Stored + mutated | Cannot drift from its lines | Recomputed on each change |
| Polling dashboard | WebSockets | No broadcast stack for a handful of operators | 5-second staleness |

---

## 12. Future improvements

1. **Sanctum with scoped, per-user tokens** and warehouse-level policies.
2. **Incremental verification** — checkpoint balances so reconciliation is O(tail)
   rather than O(all movements).
3. **Reservation allocation strategy** — "reserve from whichever warehouse can
   fulfil fastest" as a pluggable strategy, mirroring `ShippingProviderInterface`.
4. **Pick/pack workflow — and why it is not a small job.**

   The brief lists Picked and Packed as business stages. This engine implements
   reserve → ship, and the two fields are deliberately not reported by the API
   rather than published as permanent zeroes.

   The obvious estimate ("two endpoints and two enum cases") is wrong, because
   **picking changes neither `on_hand` nor `reserved`** — it moves stock *within*
   the reserved bucket. The ledger records exactly two signed deltas, so a `pick`
   movement would carry `0/0` and `inventory:verify` would reconcile nothing
   about it. Two honest options:

   - **Add `picked_delta` / `packed_delta` to `inventory_movements`.** Keeps one
     ledger for everything, at the cost of a migration on the largest table and a
     wider reconciliation query.
   - **Model pick/pack on the reservation** ("this claim has been picked"), with
     `inventory.picked_qty` becoming a derived aggregate. Cleaner conceptually —
     picking is an event against a *claim*, not against a stock position — but a
     larger refactor and it splits fulfilment state across two places.

   The second is probably right. Either way it touches the reconciliation
   guarantee, which is the last mechanism worth destabilising late in a build.

   What already exists: the columns, the CHECK chain
   `packed ≤ picked ≤ reserved ≤ on_hand`, and the clamp in `InventoryLedger::post()`
   that shrinks both when a shipment consumes the reservation above them. All of
   it is currently inert — with both columns at 0 the constraints are trivially
   satisfied and `min(0, n)` is always 0 — but it is the correct behaviour the
   moment the workflow lands.
5. **Outbox pattern for carrier calls** — currently a crash between commit and
   `dispatch()` is caught by the scheduled sweep; an outbox would make it
   immediate rather than eventual.
6. **Backorders** — first-class, so a shortfall becomes a queued claim that
   auto-reserves on the next goods receipt instead of just an error.
7. **Domain events** (`StockReserved`, `StockShipped`) for downstream systems,
   emitted only on non-replayed operations.
8. **Observability** — metrics on lock wait time, reservation failure rate, and
   `dispatched` shipments older than N minutes, which is the single best early
   warning that a carrier integration has gone quiet.
