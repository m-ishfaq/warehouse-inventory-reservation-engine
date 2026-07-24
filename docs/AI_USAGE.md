# AI Usage

## 1. How AI was used

AI tooling (Claude, via Claude Code) was used throughout this build, primarily as
an implementation accelerator working from an agreed design.

The workflow was:

1. **Requirements extraction.** The quest brief arrived as a 13-page scanned PDF
   with no text layer. AI was used to extract page images and read them, then to
   summarise the requirements and — importantly — the _grading weights_, which
   drove every subsequent scope decision (65% of the score is inventory
   correctness + concurrency + architecture; the UI is worth effectively nothing,
   so the UI is deliberately minimal).

2. **Design before code.** A written plan (`PLAN.md`) was produced and reviewed
   first: schema, the five intentionally-undefined business rules, the
   concurrency strategy, and a cut-line for what to drop under time pressure.
   No implementation began until the design was settled.

3. **Implementation.** Migrations, domain services, jobs, commands, HTTP layer,
   tests and documentation were written with AI assistance against that plan.

4. **Verification.** Static syntax checks were run across the codebase. The
   database, migrations and test suite were run manually rather than by the AI —
   a deliberate boundary, so that no schema or data change happened without a
   human executing it.

---

## 2. Main prompts and workflows relied on

- Extract and summarise the quest PDF; identify the grading weights.
- Produce an implementation plan sized to the remaining time, with an explicit
  cut order.
- Implement each layer in dependency order: schema → enums/models → ledger and
  idempotency → domain services → shipping → jobs/commands → HTTP → tests → docs.
- Write tests for each named failure scenario in the brief, plus a genuinely
  parallel concurrency test rather than a simulated one.

The productive pattern was **specify the invariant, then ask for the
implementation** — e.g. "the ledger must be the only writer of quantities and
must refuse to run outside a transaction" — rather than describing code to write.
Stating the property produced better code than describing the mechanism.

---

## 3. What was generated vs. designed

| Area                                              | Character of the work                                                                                      |
| ------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| Database schema, migrations, CHECK constraints    | Design decided first (ledger-as-truth, two signed deltas, three-layer overselling defence), then generated |
| `InventoryLedger`, `IdempotencyGuard`             | Core mechanisms specified deliberately; code generated to match                                            |
| Domain services (reservation, shipment, transfer) | Generated against stated invariants                                                                        |
| Enums as state machines                           | Design choice — transition tables live in the enum, not in services                                        |
| Controllers, form requests, middleware            | Largely generated; conventional Laravel                                                                    |
| Tests                                             | Scenarios taken from the brief; the parallel-process approach was a deliberate choice over a simulated one |
| Documentation                                     | Generated from the decisions above                                                                         |

---

## 4. Engineering decisions you made independently

- Deducting inventory **on confirmation rather than on dispatch**, which is what
  makes a carrier timeout recoverable.
- Choosing **pessimistic locking over optimistic**, and why retry storms on a hot
  SKU are worse than reduced throughput.
- **Reserved stock is pinned to its warehouse** rather than re-pointing
  reservations on transfer.
- Making reservations **all-or-nothing by default**, with partial as an explicit
  opt-in.
- Not releasing reservations when a carrier **permanently rejects** a shipment.
- Using a **shared API token** instead of installing Sanctum, and documenting it
  as a limitation rather than hiding it.

---

## 5. AI suggestions rejected or corrected

**Found by testing, not by review — the two worth talking about:**

1. **A deadlock the ordered-lock design was supposed to make impossible.**
   `lockPairs()` called `INSERT IGNORE` unconditionally before taking its ordered
   locks. InnoDB takes a _shared_ lock on the row a duplicate key collides with,
   so eight concurrent reservations all held S on the same row and then all asked
   for X — a textbook S→X upgrade deadlock, entirely outside the reach of the
   ascending-id ordering because the offending lock was taken _before_ the ordered
   acquisition began. The docblock claiming deadlock was "structurally impossible"
   was too strong and has been corrected.
   Caught because the concurrency test asserts every _loser_ failed with
   `insufficient_stock` **specifically**. A weaker assertion would have passed:
   the deadlocked transactions still lost, and stock was still never oversold. It
   would have shipped as intermittent 500s under load.

2. **Reservations validated stock but never the order line.** You could reserve
   100 units against a line that ordered 5; only the DB CHECK constraint stopped
   it, as a 500 with raw SQL in the body. Found by clicking the Postman request
   twice. Fixed with a second ceiling and a distinct `exceeds_ordered_quantity`
   error code — deliberately _not_ folded into `insufficient_stock`, because one
   resolves with time and the other never does.

**Corrections during implementation:**

- `IdempotencyGuard` read the stored response with `->value('response')`, which
  bypasses Eloquent's array cast and returns raw JSON. Changed to fetch the model.
- The guard only opened a transaction on the keyed path, so any keyless call would
  have tripped the ledger's transaction assertion. Atomicity is now unconditional;
  only the replay bookkeeping is optional.
- The `demo:scenario` output for duplicate webhooks read _"shipped 4 more units"_
  on the replay. Nothing shipped twice — a replay returns the original stored
  response — but the wording said the opposite of what happened. It now prints the
  `ship` **movement count** before and after, which cannot be misread.
- A test asserted `InventoryMovement::query()->count() === 1` — a global count
  expressing a local claim. It passed alone and failed in a full run once a
  `DatabaseTruncation` class began committing fixtures. Scoped to the position
  under test.
- The first plan assumed a hand-written Laravel skeleton; scrapped in favour of
  the official installer.

---

## 6. What differentiates this submission

1. **The ledger is verifiable, not just present.** `php artisan inventory:verify`
   replays every movement and asserts the cache matches, exiting non-zero on
   drift. Most submissions will decrement a column.
2. **Concurrency is proven, not asserted.** The concurrency suite spawns real
   parallel OS processes synchronized on a shared start time. It also asserts
   that every _loser_ failed with `insufficient_stock` specifically — a deadlock
   would also look like "failure", and would mean the design is not holding.
3. **Idempotency is one table, not scattered `try/catch`.** A single unique index
   covers duplicate commands, re-executed jobs, duplicate webhooks and worker
   retries.
4. **The invariant is structural.** DB CHECK constraints mean negative stock is
   unrepresentable, not merely unlikely; the ledger refuses to run outside a
   transaction; movements throw on update and delete.
5. **`demo:scenario` makes it demonstrable.** Ten scenarios, each printing stock
   before and after and reconciling the ledger afterwards.
6. **Trade-offs are written down**, including the ones that are weaknesses.

---

## 7. Level of ownership

**Strong on the core, honest about the edges.**
I own the inventory core completely — the ledger, the locking strategy, the
idempotency mechanism, and the shipment lifecycle. I can modify any of it live
and explain why each guarantee holds. The peripheral layers (the dashboard, the
Postman collection, parts of the HTTP scaffolding) are conventional Laravel that
I reviewed rather than laboured over, and I would need a moment with the file
before changing them.

### What I can explain and modify without notes

- Why availability is re-read **inside** the lock, and why validating it in
  the FormRequest would be a bug rather than a redundancy
- Why the idempotency re-read needs `lockForUpdate()` — REPEATABLE READ
  serves a plain `SELECT` from a snapshot taken before the winner committed
- Why locks are acquired in ascending `inventory.id` order, and why that
  alone is **not** sufficient
- Why inventory is deducted on confirmation and never on dispatch
- Why the concurrency test spawns OS processes instead of opening two
  connections in one process
- What happens, table by table, when the same carrier webhook arrives twice

Reference files: `InventoryLedger`, `IdempotencyGuard`, `ShipmentService`,
`ReservationService`, `tests/Concurrency/ConcurrentReservationTest`.

### What I would need to re-read first

- The exact InnoDB lock modes behind the S→X upgrade deadlock in §5. I
  understand the shape of the failure and the fix; I would want the manual open
  before arguing lock-mode specifics.
- The Laravel queue internals — how `WithoutOverlapping` and `$backoff` are
  implemented, as opposed to what they do.
- The precise semantics of `DatabaseTruncation` versus `RefreshDatabase`, which
  I learned the hard way when a global count assertion started failing only in
  full-suite runs.

### What I found myself, not from a suggestion

- The **order-line ceiling gap** (§5.2). Found by sending the same reservation
  request from Postman two or three times and getting a 500 with raw SQL in the
  body. The engine validated stock but never checked whether the order line had
  anything left to allocate.
- The **duplicate-webhook demo output** claiming "shipped 4 more units" on a
  replay — a wording bug that stated the opposite of what the system did, and
  would have undermined the exact scenario it was meant to prove.

### With more time, the first three things I would change

1. Implement pick/pack properly — which means deciding between extra delta
   columns on `inventory_movements` and modelling it on the reservation
   (ARCHITECTURE §12). I chose not to start it late rather than half-finish it.
2. Replace the shared bearer token with Sanctum and per-warehouse policies.
3. Make `inventory:verify` incremental, so reconciliation is O(tail) rather than
   O(every movement ever written).
