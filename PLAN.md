# Quest #87 — Warehouse Inventory Reservation Engine — Implementation Plan

**Deadline:** submission July 25, 2026. **Today:** July 24, 2026.
**Stack:** PHP 8.3, Laravel 11, MySQL 8 (InnoDB), Blade + Bootstrap + jQuery, Pest.

The grader's weighting drives everything:

| Area                                       | Weight |
| ------------------------------------------ | ------ |
| Inventory Correctness & Business Logic     | 25%    |
| Concurrency Handling & Data Integrity      | 20%    |
| System Design & Architecture               | 20%    |
| Laravel/PHP Implementation Quality         | 15%    |
| Security Best Practices                    | 10%    |
| Testing Strategy & Coverage                | 5%     |
| Documentation, AI Transparency & Reasoning | 5%     |

**65% of the score is correctness + concurrency + architecture.** UI is worth ~0%. Build the engine, not the app.

---

## 1. Core design decisions

These are the "intentionally missing" business rules. Decide them explicitly, document each with a
trade-off — the PDF says engineering judgment is graded as heavily as the code.

| Open question                               | Decision                                                                                                                                                                                  | Rationale                                                                                                                                                            |
| ------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Can reservations expire?                    | Yes. `expires_at`, default TTL 30 min, configurable per-order. A scheduled job releases expired ones.                                                                                     | Prevents abandoned carts from permanently locking stock. Trade-off: a race between expiry and confirmation — resolved by re-validating state inside the transaction. |
| Partial shipments?                          | Shipping N of a reserved M consumes N, leaves M−N reserved and open. Order closes when all items are consumed or explicitly cancelled.                                                    | Matches real WMS behaviour; avoids silently dropping the remainder.                                                                                                  |
| Transfer while reserved?                    | Only _unreserved_ stock is transferable. Reserved qty is pinned to its warehouse.                                                                                                         | Keeps reservations honest without a distributed re-allocation problem. Alternative (re-point reservations) noted in ARCHITECTURE.md as future work.                  |
| Do reservations lock inventory immediately? | Yes. Reserving increments `reserved_qty`; `on_hand_qty` is untouched until shipment confirmation.                                                                                         | `available = on_hand − reserved` is the only number the sales side sees, so overselling is structurally impossible.                                                  |
| How is overselling prevented?               | Three layers: (1) `SELECT … FOR UPDATE` row lock, (2) revalidate availability inside the txn, (3) DB `CHECK` constraints + unsigned columns so a bug still cannot produce negative stock. | Defense in depth. Layer 3 is what turns "probably correct" into "provably correct."                                                                                  |

### The two load-bearing ideas

**A. Append-only ledger as source of truth.**
`inventory_movements` is immutable. `inventory` is a materialised cache updated _in the same
transaction_ as the movement row. Every quantity in the system is reconstructible by replaying the
ledger — which gives you a real audit trail and a `inventory:verify` command that reconciles cache
vs. ledger. This is the single biggest differentiator vs. a typical "just decrement a column"
submission.

**B. Idempotency is a first-class table, not a `try/catch`.**
An `idempotency_keys` table with a unique index on `(scope, key)`. Every mutating operation —
reserve, release, confirm shipment, webhook — claims a key inside the transaction before doing work.
Replay returns the stored result instead of re-executing. This single mechanism covers _four_ of the
required failure scenarios (command run twice, job executed twice, duplicate webhook, worker retry).

### Concurrency strategy

Pessimistic locking (`SELECT … FOR UPDATE`) as primary, because correctness beats throughput here and
MySQL/InnoDB handles it well at this scale. **Locks are always acquired in a deterministic order**
(sorted by `inventory.id`) so multi-line orders can't deadlock. A `version` column supports optimistic
checks where useful. Document why you did _not_ choose optimistic-only (retry storms under contention)
or Redis locks (adds a second source of truth that can drift from the DB).

---

## 2. Database schema

```
products            id, sku (unique), name, unit, is_active, timestamps
warehouses          id, code (unique), name, is_active, timestamps

inventory           id, product_id, warehouse_id, on_hand_qty, reserved_qty,
                    picked_qty, packed_qty, version, timestamps
                    UNIQUE(product_id, warehouse_id)
                    CHECK (on_hand_qty >= 0)
                    CHECK (reserved_qty >= 0 AND reserved_qty <= on_hand_qty)
                    CHECK (picked_qty <= reserved_qty)

orders              id, order_number (unique), customer_ref, status, timestamps
order_items         id, order_id, product_id, qty_ordered, qty_reserved,
                    qty_shipped, qty_cancelled, timestamps

reservations        id, order_item_id, product_id, warehouse_id, qty,
                    qty_consumed, status, expires_at, idempotency_key, timestamps
                    INDEX(status, expires_at)   -- expiry sweep
                    INDEX(product_id, warehouse_id, status)

reservation_events  id, reservation_id, from_status, to_status, qty_delta,
                    reason, actor, context(json), created_at   -- append-only history

shipments           id, order_id, warehouse_id, status, provider_ref,
                    attempts, last_error, dispatched_at, confirmed_at, timestamps
shipment_items      id, shipment_id, reservation_id, qty_requested, qty_shipped

inventory_movements id, product_id, warehouse_id, type, qty_delta,
                    balance_after, reference_type, reference_id,
                    idempotency_key (unique), occurred_at   -- APPEND ONLY, no updates

idempotency_keys    id, scope, key, status, response(json), locked_at, completed_at
                    UNIQUE(scope, key)

processed_webhooks  id, provider, provider_event_id (unique), payload(json),
                    signature_valid, processed_at
```

Movement types (enum): `receipt`, `reserve`, `release`, `pick`, `pack`, `ship`, `deliver`,
`transfer_out`, `transfer_in`, `adjustment`, `cancel`.

Reservation statuses (enum): `pending` → `active` → (`partially_consumed`) → `consumed` |
`released` | `expired`. All transitions go through a guarded state machine; illegal transitions throw.

---

## 3. Application architecture

```
app/
  Domain/Inventory/
    Enums/            ReservationStatus, MovementType, ShipmentStatus
    DTOs/             ReserveStockRequest, ReservationResult, ShipmentOutcome
    Exceptions/       InsufficientStockException, InvalidStateTransitionException,
                      ConcurrentModificationException
    Services/
      InventoryLedger        -- ONLY writer of movements + inventory cache
      ReservationService     -- reserve / release / expire / partial-consume
      ShipmentService        -- dispatch, confirm, partial, fail
      StockQueryService      -- available / reserved / picked / shipped / history
      IdempotencyGuard       -- claim(scope,key,callable)
    Contracts/
      ShippingProviderInterface
      InventoryRepositoryInterface
  Infrastructure/Shipping/
    MockShippingProvider     -- random: success / permanent fail / timeout /
                                duplicate confirm / delayed confirm
                                seedable via config for deterministic demos
  Jobs/            ProcessShipmentJob, ConfirmShipmentJob, ExpireReservationsJob
  Console/Commands/ shipments:process, reservations:expire, inventory:verify,
                    demo:scenario {name}
  Http/
    Controllers/Api/  ReservationController, ShipmentController, StockController
    Controllers/Web/  DashboardController
    Requests/         FormRequest validation for every mutating endpoint
    Middleware/       VerifyShippingWebhookSignature
```

**SOLID / patterns to name explicitly in the video:** Strategy (`ShippingProviderInterface` bound in a
service provider — swap mock for real without touching domain code), Repository, Command/Action
objects, State machine for reservation lifecycle, Ledger/Event-sourcing-lite for movements, DI
throughout (no facades inside domain services, so everything is unit-testable).

**`demo:scenario` is worth building.** A command that deterministically drives each of the 10 failure
scenarios end-to-end and prints before/after stock. It turns the video walkthrough from hand-waving
into a live demonstration, and it costs ~1 hour.

---

## 4. Failure scenarios → how each is handled

The PDF requires demonstrating at least five. Build tests for all ten; they double as the video script.

| #   | Scenario                                   | Mechanism                                                                                                         |
| --- | ------------------------------------------ | ----------------------------------------------------------------------------------------------------------------- |
| 1   | Two users reserve last item simultaneously | `FOR UPDATE` row lock + revalidate inside txn → one succeeds, one gets `InsufficientStockException`               |
| 2   | Reservation command runs twice             | `idempotency_keys` unique claim → second call returns first result, no double reserve                             |
| 3   | Duplicate shipment webhook                 | `processed_webhooks.provider_event_id` unique → second is acknowledged and ignored                                |
| 4   | Worker retry after failure                 | Whole operation in one txn → partial work rolled back; idempotency key makes retry safe                           |
| 5   | Shipment timeout then late confirmation    | Shipment stays `dispatched`; late confirm matched by `provider_ref`, guarded transition prevents double deduction |
| 6   | Reservation cancellation                   | Release returns qty to available, writes `release` movement + history row                                         |
| 7   | Partial shipment                           | Consume N, leave M−N active; `qty_consumed` tracks it                                                             |
| 8   | Inventory transfer while reserved          | Transfer limited to `on_hand − reserved`; attempt to over-transfer rejected                                       |
| 9   | Concurrent inventory updates               | Ordered lock acquisition prevents deadlock; test drives N parallel connections                                    |
| 10  | SQL rollback after failure                 | Deliberate exception mid-transaction → ledger and cache both unchanged, verified by `inventory:verify`            |

---

## 5. Security (10% of grade — cheap points, don't skip)

- Sanctum token auth on the API; policies for per-warehouse authorization.
- FormRequest validation on every mutating route; `$fillable` allow-lists on all models.
- Webhook: HMAC-SHA256 signature verification (`hash_equals`), timestamp window, replay protection via `processed_webhooks`.
- Rate limiting on reservation endpoints (throttle abuse of stock-locking).
- Eloquent/parameter bindings only — no raw interpolation; `DB::raw` only with bindings.
- CSRF on web routes, Blade escaping by default, security headers.
- No secrets in repo; `.env.example` provided.

---

## 6. Testing

Pest. Aim for meaningful tests over count.

- **Unit:** reservation state machine transitions, availability math, ledger balance calculation.
- **Feature:** each of the 10 scenarios above.
- **Concurrency:** a real test that opens multiple DB connections and races `FOR UPDATE` — not a mocked simulation. This is the test that proves the 20% concurrency criterion.
- **Invariant test:** after any randomized sequence of operations, `sum(movements) == inventory cache` and no negative quantities.

Screenshot the green run — it's a required submission artifact.

---

## 7. Deliverables checklist

- [ ] Source + migrations + seeders + factories + tests (GitHub/GitLab)
- [ ] `README.md` — setup, how to run, how to test, assumptions, known limitations
- [ ] `docs/ARCHITECTURE.md` — domain model, reservation lifecycle, movement strategy, concurrency, DB design, security, scaling, trade-offs, future improvements
- [ ] `docs/AI_USAGE.md` — how AI was used, what you rejected, decisions you made yourself, what differentiates this solution
- [ ] Screenshot of passing tests
- [ ] 15–20 min video: intro 2–3 / architecture 5 / failure demos 5–7 / testing 2–3 / AI usage 2–3 / future 1–2

---

## 8. Schedule (~1.5 days)

**Day 1 morning — foundation (3h)**
Laravel install, migrations for all 11 tables incl. CHECK constraints, models + relationships,
enums, factories, seeder (3 warehouses × 20 products with stock).

**Day 1 afternoon — the engine (5h)**
`InventoryLedger`, `IdempotencyGuard`, `ReservationService` (reserve/release/expire), locking
strategy, custom exceptions. Unit tests alongside.

**Day 1 evening — shipping + jobs (4h)**
`ShipmentService`, `MockShippingProvider` with the five random outcomes, `ProcessShipmentJob` +
retries/backoff, webhook endpoint with signature + dedup, artisan commands.

**Day 2 morning — tests + demo (4h)**
All 10 failure-scenario tests, the real concurrency test, the invariant test, `demo:scenario`
command, `inventory:verify`. Screenshot.

**Day 2 midday — API + thin UI (2h)**
REST endpoints (stock, reserve, release, shipments) + one Bootstrap/jQuery dashboard page showing
live stock buckets, open reservations, movement history. **This is the cut line** — if time is short,
ship the API and skip the UI; it's worth ~0% directly.

**Day 2 afternoon — docs + video (3h)**
README, ARCHITECTURE.md, AI_USAGE.md, record the walkthrough.

**If you fall behind, cut in this order:** UI → reservation expiry → transfer between warehouses.
Never cut: the concurrency test, the idempotency layer, or ARCHITECTURE.md.
