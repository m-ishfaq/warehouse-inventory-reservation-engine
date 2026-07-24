# Warehouse Inventory Reservation Engine

Innov8 Hiring Quest #87 — the inventory core of an ERP: stock across multiple
warehouses, safe reservations, shipment settlement, and correctness that survives
retries, duplicate webhooks, crashes and concurrent requests.

The guiding constraint throughout: **inventory correctness beats feature count.**

---

## The one-paragraph version

Stock lives in an append-only ledger (`inventory_movements`). The `inventory`
table is a materialised cache of that ledger, updated in the same transaction as
the movement that justifies it, so the two can never disagree —
`php artisan inventory:verify` proves it by replaying every movement ever posted.
Reservations hold stock without removing it (`available = on_hand − reserved`),
so overselling is structurally impossible rather than merely unlikely.
Concurrency is handled with pessimistic row locks acquired in a deterministic
order, and every mutating operation can be made exactly-once with an
`Idempotency-Key`.

---

## Requirements

| | |
|---|---|
| PHP | 8.3+ |
| Laravel | 13 |
| Database | **MySQL 8.0.16+** — not SQLite (see below) |
| Queue | `database` driver (or Redis) |

**Why MySQL specifically.** The engine depends on two InnoDB features SQLite
cannot provide: `SELECT ... FOR UPDATE` row locking, and enforced `CHECK`
constraints. Testing against SQLite would silently skip exactly the guarantees
this project is about, so the test suite points at MySQL too.

---

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create both databases:

```sql
CREATE DATABASE warehouse_inventory   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE warehouse_inventory_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Set your credentials in `.env`, then generate an API token:

```bash
php artisan inventory:token --set     # generates and writes INVENTORY_API_TOKEN
php artisan config:clear
```

Build and seed:

```bash
php artisan migrate
php artisan db:seed
php artisan inventory:verify     # must report every position reconciled
```

Seeding posts opening stock **through the ledger**, not straight into the
inventory table — otherwise `inventory:verify` would report drift on a fresh
install and the invariant would be decorative.

---

## Running it

Three long-running processes, ideally in three terminals:

```bash
php artisan serve                # API + dashboard at http://localhost:8000/dashboard
php artisan queue:work           # runs ProcessShipmentJob and ExpireReservationsJob
php artisan schedule:work        # enqueues the expiry sweep every minute
```

**`queue:work` is not optional.** `QUEUE_CONNECTION=database`, so without a worker
a created shipment sits at `pending` and the carrier is never called. Queueing
rather than calling the carrier inline is deliberate: it keeps a slow or failing
carrier out of the web tier, it is the only way to get retries with backoff, and
it makes "the same background job executes twice" a real at-least-once delivery
you can demonstrate rather than a hypothetical.

For a quick one-off without a worker: `php artisan shipments:process --sync`.
Setting `QUEUE_CONNECTION=sync` also works, but then the retry and duplicate
behaviour never fires — don't demo that way.

### Operator commands

```bash
php artisan shipments:process                  # enqueue everything pending
php artisan shipments:process --shipment=1 --sync   # one, inline
php artisan shipments:process --include-failed # retry carrier rejections
php artisan reservations:expire                # run the TTL sweep by hand
php artisan inventory:verify                   # reconcile ledger vs cache
php artisan inventory:token                    # print a new API token
php artisan demo:webhook 1                     # send a signed carrier callback
```

### See it survive each failure

```bash
php artisan demo:scenario              # list the ten scenarios
php artisan demo:scenario last-item    # run one
php artisan demo:scenario --all        # run all ten
```

Each scenario builds an isolated fixture, performs the failure, prints stock
before and after, and reconciles the ledger against the cache. This is the
backbone of the video walkthrough.

| Scenario | What it demonstrates |
|---|---|
| `last-item` | Two users race for the last unit; one wins, none oversold |
| `duplicate-command` | Same `Idempotency-Key` twice → one reservation |
| `duplicate-webhook` | Same carrier event twice → one deduction |
| `worker-retry` | Worker crashes after deducting, job re-runs → still one deduction |
| `timeout-then-confirm` | Carrier times out, confirms an hour later → settles correctly |
| `cancellation` | Release returns stock; a second release is a no-op |
| `partial-shipment` | Ship 3 of 5; remainder stays reserved and open |
| `transfer` | Reserved stock cannot leave the warehouse that promised it |
| `expiry` | TTL sweep returns abandoned stock to available |
| `rollback` | Crash mid-transaction leaves no orphan movement |

---

## Tests

```bash
php artisan test                              # unit + feature
php artisan test --testsuite=Concurrency      # real parallel processes
php artisan inventory:verify                  # ledger reconciliation
```

The concurrency suite is separate because it **spawns real OS processes** that
hit the same stock row simultaneously. It cannot use `RefreshDatabase` (whose
uncommitted wrapping transaction would be invisible to child processes), and
concurrency cannot be honestly simulated inside one single-threaded PHP process
— the loser's `FOR UPDATE` blocks, and the test would deadlock waiting for a
commit it is itself blocking.

---

## API

All endpoints under `/api`, bearer token in `Authorization`.
Send an `Idempotency-Key` header on every mutating call — **strongly
recommended**; without one, a client that retries after a network blip will
reserve twice.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/stock` | Current positions |
| `GET` | `/api/stock/{product}/{warehouse}` | One position |
| `GET` | `/api/stock/{product}/{warehouse}/movements` | Audit trail |
| `GET` | `/api/reservations` | Open claims |
| `GET` | `/api/orders/consuming-inventory` | Orders that consumed stock |
| `POST` | `/api/reservations` | Reserve |
| `DELETE` | `/api/reservations/{id}` | Release (full or partial) |
| `POST` | `/api/shipments` | Create + queue a shipment |
| `GET` | `/api/shipments/{id}` | Shipment detail |
| `POST` | `/api/transfers` | Move stock between warehouses |
| `POST` | `/api/webhooks/shipping/confirm` | Carrier callback (HMAC-signed) |

```bash
curl -X POST http://localhost:8000/api/reservations \
  -H "Authorization: Bearer $INVENTORY_API_TOKEN" \
  -H "Idempotency-Key: order-1001-reserve" \
  -H "Content-Type: application/json" \
  -d '{"order_id":1,"lines":[{"order_item_id":1,"product_id":1,"warehouse_id":1,"qty":3}]}'
```

Domain failures return a machine-readable code and structured context, not prose —
so a client branches on `error` rather than parsing English out of `message`:

```json
{ "error": "insufficient_stock",
  "message": "Insufficient stock for product 1 in warehouse 1: requested 5, available 2.",
  "context": { "requested": 5, "available": 2, "shortfall": 3 } }
```

| `error` | HTTP | Meaning |
|---|---|---|
| `insufficient_stock` | 409 | The warehouse does not have it. May resolve later |
| `exceeds_ordered_quantity` | 409 | The customer did not order it. Will never resolve on its own |
| `transfer_not_allowed` | 409 | Stock is reserved, or source equals destination |
| `invalid_state_transition` | 409 | Illegal lifecycle move (e.g. settling a settled shipment) |
| `idempotency_conflict` | 409 | An identical operation is still in flight — back off and retry the same key |
| `shipment_timeout` | 504 | Carrier did not answer; the job will retry |
| `unauthenticated` | 401 | Missing or wrong bearer token |
| `invalid_signature` / `signature_expired` | 401 | Webhook HMAC failed or fell outside the tolerance window |
| `rate_limited` | 429 | Reservation throttle tripped |
| `inventory_invariant_violation` | 500 | Reaching this means an engine bug, not bad input — hence 5xx |

`insufficient_stock` and `exceeds_ordered_quantity` are deliberately **not** one
code. They imply opposite client behaviour: wait and retry versus fix the request.
Collapsing them would tell a caller to keep retrying something that can only fail.

---

## Assumptions

The quest leaves five business rules intentionally undefined. Ours, with the
reasoning in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md):

1. **Reservations expire.** Configurable TTL, default 30 minutes, released by a
   scheduled sweep. Abandoned checkouts must not lock stock forever.
2. **Partial shipments consume what shipped.** The remainder stays reserved and
   open. Closing the whole reservation would silently drop goods the customer is
   still owed.
3. **Reserved stock cannot be transferred.** Only the unreserved surplus moves.
   Re-pointing reservations at another warehouse sounds flexible but breaks
   carrier routing and pick lists already on the floor.
4. **Reservations lock immediately.** Reserving raises `reserved_qty`;
   `on_hand_qty` is untouched until a shipment confirms.
5. **Overselling is prevented in three layers.** Row lock → in-transaction
   re-check → DB `CHECK` constraints.

Beyond the five, one rule the brief does not raise but an ERP needs:

6. **A reservation has two independent ceilings.** It may claim no more than
   available stock *and* no more than the order line still has to allocate
   (`ordered − reserved − shipped − cancelled`). Checking only stock would let a
   caller reserve 100 units against a line that ordered 5 — the DB constraint
   catches that, but a constraint reached is a bug, not a validation.

Additional assumptions: single-currency and pricing are out of scope (this is the
inventory core, not an ERP); warehouse selection is caller-supplied rather than
algorithmic.

---

## Known limitations

- **Auth is a single shared bearer token**, not per-user. Sanctum or Passport is
  the production answer; swapping in `auth:sanctum` is a one-line middleware
  change. Documented rather than hidden.
- **The mock carrier is in-process.** A real integration needs HTTP timeouts,
  circuit breaking, and a dead-letter queue.
- **`inventory:verify` scans all positions.** At millions of SKUs this wants to
  be incremental (checkpoint balances + verify the tail).
- **No pick/pack workflow.** The brief lists Picked and Packed as business
  stages; this engine implements **reserve → ship** only. The schema carries
  `picked_qty` / `packed_qty` and the CHECK chain
  `packed ≤ picked ≤ reserved ≤ on_hand`, but nothing raises them, so the API
  does not report them — publishing a field that is structurally always `0`
  reads as a broken feature rather than as unbuilt scope.
  Implementing it is not just wiring two endpoints: picking changes neither
  `on_hand` nor `reserved`, so it does not fit the ledger's two-delta model.
  It needs either extra delta columns on `inventory_movements` or pick/pack
  modelled on the reservation with `inventory.picked_qty` derived. See
  [ARCHITECTURE §12](docs/ARCHITECTURE.md).
- **Dashboard polls every 5 seconds.** Fine for a handful of operators, not for
  a wall display in a 200-person DC.
- **Reservation warehouse allocation is manual.** No "reserve from wherever has
  stock" strategy.

---

## Documentation

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — domain model, reservation
  lifecycle, movement strategy, concurrency, DB design, security, scaling,
  trade-offs, future improvements.
- [docs/postman/inventory-engine.postman_collection.json](docs/postman/inventory-engine.postman_collection.json)
  — importable collection covering all endpoints, with assertions, id capture
  between requests, and pre-request scripts that sign the carrier webhook.
  Set `token` and `webhook_secret` as collection variables, then run folders
  `00` → `05` in order.
- [docs/AI_USAGE.md](docs/AI_USAGE.md) — how AI was used, what was rejected,
  which decisions were made independently.
