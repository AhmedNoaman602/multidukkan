# MultiDukkan — Architecture Decisions

Decisions that are **visible in the code**, with the trade-off each one bought. Every entry was
verified against the implementation on 2026-08-30.

**This is not a replacement for the ADRs.** Eight formal ADRs live in
[`01-architecture/decisions/`](01-architecture/decisions/) and are the authoritative record — they
are immutable and can only be superseded by a new ADR. This document does two things the ADRs don't:

1. Summarises them in one place with the trade-off foregrounded, so you can scan them.
2. **Documents the decisions that have no ADR** — the ones that were made by writing code and never
   written down. Those are marked **📌 undocumented**.

Labels: ✅ Confirmed · 🔍 Inferred · ❓ Unclear · ⚠️ Potential issue.

---

## Part A — decisions with a formal ADR

### A1. Sanctum personal access tokens ([ADR-001](01-architecture/decisions/ADR-001-sanctum-token-auth.md))

**Chosen**: Laravel Sanctum with personal access tokens (not SPA cookie mode).
**Why it makes sense**: the API serves a cross-origin React SPA today and mobile apps later. Tokens
work identically for both; cookie mode does not.
**Alternative rejected**: Passport/OAuth2 — a full OAuth server for a first-party SPA with no
third-party clients is pure overhead.
**Trade-off**: easier — no CSRF/cookie/domain choreography, trivially works from any client. Harder —
token storage is the client's problem (`localStorage` here, which is XSS-reachable), and there is no
configured token expiry ❓.

### A2. String role column ([ADR-002](01-architecture/decisions/ADR-002-string-role-column.md))

**Chosen**: `users.role` is a plain string; three roles; permissions live in policies.
**Why**: three stable roles, and permission logic that depends as much on `store_id` as on the role
name. A roles/permissions schema would add three tables and a join to express `in_array($role, [...])`.
**Alternative rejected**: spatie/laravel-permission.
**Trade-off**: easier — reading a policy tells you the whole rule; no cache invalidation, no seeding.
Harder — adding a fourth role means editing 11 policy files, and there is no per-user permission
override. Correct for now; the thing to watch for is a customer asking for custom roles.

### A3. LedgerService is the only source of financial truth ([ADR-003](01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md))

**Chosen**: every balance/debt/credit number comes from `LedgerService`.
**Why**: **a real production bug.** Three controllers each computed `order.total − payments`
slightly differently and disagreed on screen.
**Alternative rejected**: a cached `customers.balance` column — faster, and wrong the first time
anything writes a ledger entry without updating it.
**Trade-off**: easier — one place to fix a formula, one place to test. Harder — every balance is
`SUM` over history, so it needs the batch methods (`getBalancesForCustomers`) and the right indexes
in lists. An N+1 from per-row `getBalance()` has already shipped once and been fixed.

**This is the most important decision in the codebase.** If you remember one thing from this
document, remember that a controller computing `total − payments` is the founding sin here.

### A4. `orders.total` is stored ([ADR-004](01-architecture/decisions/ADR-004-stored-order-total.md))

**Chosen**: a stored `orders.total` column, written at creation and thereafter only by
`LedgerService::adjustOrderCharge`. **Supersedes** the original "never store the total" rule.
**Why**: every order list, report, unpaid query and dashboard needed the total, forcing repeated
`SUM(unit_price × quantity)` subqueries — directly against the owner's stated #1 requirement, speed.
And `manual_total` (the merchant overriding the number at the till) simply cannot be derived.
**Alternative rejected**: keeping it derived — clean, and measurably slower on every screen.
**Trade-off**: easier — fast reads, and merchant overrides become expressible. Harder — a
denormalisation that must be kept in sync, protected by a single write funnel.

⚠️ Note the invariant this creates: `orders.total` **must** equal the amount on that order's
`ORDER_CHARGE` ledger entry. Nothing in the code verifies it; `adjustOrderCharge` writing both in one
call is the only thing keeping them together, and it is **not** wrapped in a transaction (see
[CODEBASE_NOTES.md](CODEBASE_NOTES.md) #2).

### A5. Snapshot pricing ([ADR-005](01-architecture/decisions/ADR-005-snapshot-pricing.md))

**Chosen**: line items copy `product_name` and `unit_price` at sale time; orders copy
`customer_name_snapshot`.
**Why**: an invoice from March must forever show what was actually sold at the price actually
charged, whatever the product row looks like today.
**Alternative rejected**: joining live product data at render time — simpler, and it silently
rewrites history every time someone edits a price.
**Trade-off**: easier — history is immutable and correct by construction; a deleted product doesn't
break old invoices. Harder — the same name lives in two places, and you must remember never to
re-join for historical display.

⚠️ **The ADR overstates the implementation.** It claims `purchase_order_items` snapshots
`product_name`. It does not — there is no such column, and no such key in
`PurchaseOrderItem::$fillable`. PO invoices join the live product row, so renaming a product **does**
change historical purchase invoices. Either the schema or the ADR needs fixing; the schema is what
ships.

### A6. Ledger append-only, with two narrow edits ([ADR-006](01-architecture/decisions/ADR-006-ledger-mutability-boundaries.md))

**Chosen**: append-only by default; exactly two in-place correction paths (`adjustPayment`,
`adjustOrderCharge`).
**Why**: pure append-only is correct for a ledger, but the business needed to fix a *typo on the same
business event* without a reversal pair cluttering the customer's statement.
**Alternative rejected**: strict append-only — a mistyped payment amount would produce three entries
for one event, on a statement that Arabic-speaking merchants read directly.
**Trade-off**: easier — clean statements for the common "cashier fat-fingered it" case. Harder —
"append-only" now has asterisks, and the exceptions must be guarded (both are: `adjustPayment`
refuses once a refund exists; `adjustOrderCharge` is gated by `ensureOrderIsEditable`).

⚠️ Enforced by convention only. No DB trigger, no model `updating` guard. Nothing prevents
`LedgerEntry::where(...)->delete()`.

### A7. Nullable `warehouse_id` on line items ([ADR-007](01-architecture/decisions/ADR-007-nullable-warehouse-on-line-items.md))

**Chosen**: null warehouse = no stock check, no movement, no transaction row.
**Why**: not every sale moves tracked stock — services, ad-hoc goods, items sold before inventory was
set up. Forcing a warehouse would block real sales.
**Alternative rejected**: a fake "no warehouse" warehouse — pollutes every inventory report and still
needs special-casing.
**Trade-off**: easier — the POS never blocks on inventory setup. Harder — **every** stock-touching
code path needs an `if ($warehouseId)` branch, and forgetting it throws `firstOrFail` at runtime.
That omission is listed in the AI guide as a recurring mistake.

⚠️ **Only half-implemented.** `order_items.warehouse_id` is nullable ✅, but
`purchase_order_items.warehouse_id` is **NOT NULL** in the migration, contradicting the ADR. And
`StoreOrderRequest`/`StoreOrderItemRequest` both mark `warehouse_id` **required**, so the null branch
is currently unreachable through the API for new orders — it exists only for legacy rows.

### A8. Weighted-average costing ([ADR-008](01-architecture/decisions/ADR-008-weighted-average-costing.md))

**Chosen**: `products.cost_price` holds a weighted average, recomputed per PO line.
```
new_avg = (current_stock × current_cost + purchased_qty × line_cost_per_base_unit)
          / (current_stock + purchased_qty)
```
**Why**: small merchants think "what does this item cost me on average", not in lots. Profit
reporting needs *a* cost basis, and this is the one they can explain.
**Alternatives rejected**: FIFO/lot tracking (needs a lots table and cost layers — a different
product); last-purchase-price (swings wildly with one odd purchase).
**Trade-off**: easier — one number per product, cheap to read, intuitive. Harder — cost history is
lost as soon as it's averaged in, and there is no way to answer "what did the units I sold in March
actually cost me".

⚠️ **`cost_price` is never rolled back when a PO is cancelled.** A cancelled purchase permanently
influences the average. Confirmed, undocumented, and possibly unintended.

---

## Part B — decisions with no ADR 📌

These shape the system as much as the ADRs above but were never written down. Each one is inferred
from consistent implementation, not from a stated intention — so the "why" is my reading of the code,
clearly labelled.

### B1. 📌 Fat services, thin controllers — but only for writes

**What was chosen**: all multi-step business logic lives in `app/Services/`; controllers authorize,
delegate, and shape the response.
**Why it makes sense** 🔍: an order creation touches six tables. Putting that in a controller means
every future caller (a console command, a bulk importer, an API v2) re-implements the ordering and
the transaction boundary.
**Alternative**: fat controllers, or a command/handler bus. The first doesn't scale past two callers;
the second is ceremony this codebase doesn't need.
**Trade-off**: easier — business logic is testable and reusable, and there's one obvious place to
look. Harder — indirection, and a `ProductService::createProduct` that is barely more than the
controller would be.

⚠️ **The decision was only half-applied.** It holds for writes. It does **not** hold for reads:
`OrderController::index`, `DashboardController::index`, `ReportController::daily` and
`AuditLogController::index` contain substantial query building and aggregation with no service
behind them. `ReportController` is 201 lines of business logic in the HTTP layer. This is the single
largest inconsistency in the backend architecture.

### B2. 📌 Tenant isolation by global scope + defence in depth

**What was chosen**: four independent layers (global scope, validation rule, policy check, explicit
controller re-check) rather than one.
**Why** 🔍: the git history tells the story — `fdf7774` "enforce tenant scoping at the model level"
then `7a329b9` "enforce tenant isolation across models" landed *after* the manual checks already
existed. The scope was added as a safety net under code that was already checking, not as a
replacement for it. The redundancy is the point.
**Alternative rejected**: a tenant middleware that sets a container-bound tenant, or a single global
scope alone. The scope alone fails open when nobody is authenticated
(`auth()->user()?->tenant_id` → `null` → no filter) — which is exactly why it can't be the only layer.
**Trade-off**: easier — a leak requires four independent failures. Harder — the redundancy looks like
sloppiness to a newcomer and invites "cleanup" PRs that remove a real safety layer.

**If you take one thing from this entry**: the seemingly-redundant
`if ($model->tenant_id !== auth()->user()->tenant_id) return 403;` after `$this->authorize(...)` is
deliberate. Leave it.

### B3. 📌 Inventory is current-state plus an audit log, not event-sourced

**What was chosen**: `inventory.quantity` is a mutable column; `inventory_transactions` is written
alongside it, never used to derive it.
**Why** 🔍: reads dominate — every product page, order form and dashboard needs current stock. Summing
a transaction log for each would be exactly the performance problem ADR-004 solved for order totals.
**Alternative rejected**: true event sourcing (derive quantity by replaying) — self-healing and
auditable by construction, but every stock read becomes an aggregate.
**Trade-off**: easier — O(1) stock reads, one row per product/warehouse. Harder — the two can drift
and **nothing detects it**. There is no reconciliation command, and no way to ask "what was stock on
3 March?" without replaying the log yourself.

🔍 Consistent with ADR-004's reasoning, which suggests the same underlying value judgement: *stored
state for speed, with a disciplined write funnel to keep it honest.* If that framing were written up
as an ADR, it would cover both.

### B4. 📌 UTC storage, three-timezone model, `DATETIME` not `TIMESTAMP`

**What was chosen**: `app.timezone = UTC` for storage; `display_timezone` for the viewer's calendar;
`business_timezone` for the shop's trading day. All conversion in one class, `LocalDateRange`.
**Why**: written out at length in the code comments. The critical insight is that **three** clocks are
needed, not two — a daily report must show identical figures to a manager in Cairo and an owner in
London, so it can't use the viewer's zone *or* UTC.
**Alternatives rejected**: storing local time (ambiguous across DST); two zones only (breaks
reports); `TIMESTAMP` columns (MySQL silently converts on read/write per session zone — `DATETIME`
stores exactly what you give it, commit `d7ce031`).
**Trade-off**: easier — no ambiguity, DST handled by PHP's tz database, and business reporting is
stable. Harder — every date filter must consciously choose `apply()` (instants) vs
`applyCalendarDate()` (DATE columns), and getting that wrong is a whole bug class.

**Why this deserves an ADR it doesn't have**: it's a five-commit architectural change
(`3988a06` → `4a8bf4b`) with a genuine alternative, and its rules are easy to violate accidentally.
It is the strongest candidate for ADR-009.

### B5. 📌 FIFO payment application

**What was chosen**: money always settles the oldest unpaid order first; leftovers become store
credit.
**Why** 🔍: it matches how a shop actually works — a customer handing over cash is paying down their
running tab, oldest debt first. Any other policy needs the merchant to decide per payment.
**Alternatives**: newest-first, proportional across all orders, or asking the user. All add a choice
to a screen that should take three seconds.
**Trade-off**: easier — no allocation UI, deterministic and explainable. Harder — a merchant who
*wants* to settle a specific order has to name it explicitly (`POST /payments` with `order_id`), and
FIFO ordering is by `created_at`, so a backdated order does not jump the queue. 🔍

### B6. 📌 Soft delete for documents, hard delete for catalogue

**What was chosen**: orders, purchase orders, customers, suppliers, expenses soft-delete. Products,
warehouses, stores, users hard-delete behind observer guards.
**Why** 🔍: documents *are* history — a cancelled order is a business event. Catalogue entities are
"things that currently exist", and the guards prevent deleting one that history depends on.
**Trade-off**: easier — cancellation is a one-line soft delete that keeps everything intact. Harder —
the split is not obvious, and the guards are the only protection.

⚠️ **This is where I'd push back on the decision as implemented.** The guards are incomplete for
exactly the cases that matter:
- A zero-stock product with adjustment history deletes cleanly, and
  `inventory_transactions.product_id` CASCADE takes that history with it.
- An emptied warehouse — precisely the state one is in when you close it — deletes cleanly and takes
  every movement that passed through it.

The principle *"a historical event isn't owned by the entity it references"* is applied to orders and
customers but not to products and warehouses. See [DOMAIN_RULES.md](DOMAIN_RULES.md) §7.

### B7. 📌 Arabic-first, with server-side messages

**What was chosen**: Arabic is the default locale on both sides. Validation and error messages come
from the API (`lang/ar/`), and the client renders them verbatim.
**Why**: the users are Arabic-speaking Egyptian merchants. This is listed in the AI collaboration
guide as a documented divergence — "do NOT translate to English".
**Trade-off**: easier — exactly one validation implementation, so the UI can never disagree with the
API about what's allowed. Harder — no offline validation, a round-trip for every error, and every
string needs both `ar` and `en` files with no CI check that they match.

### B8. 📌 React Query for reads, raw axios for writes

**What was chosen**: `useQuery` in 26 of 30 pages; `useMutation` in **zero**.
**Why** 🔍: this reads as incremental adoption rather than a decision. React Query was brought in for
caching and pagination (the visible wins) and the write half was never migrated.
**Trade-off**: easier — writes have direct, obvious control flow. Harder — manual loading state,
manual cache invalidation (forgetting it is the #1 "why didn't the UI update" bug), no optimistic
updates, and the same error-handling expression copy-pasted across ~30 files.

This is the one entry in Part B I'd call **unfinished** rather than a considered trade. See
[FRONTEND_GUIDE.md](FRONTEND_GUIDE.md) §5.

### B9. 📌 Feature tests only, no unit tests

**What was chosen**: 390 tests, all in `tests/Feature/`, run against MySQL. `tests/Unit/` exists and is empty.
**Why** 🔍: this system's bugs live in the *interaction* between layers — a service that writes stock
but not the ledger, a policy that passes but a query that doesn't scope. Feature tests catch that
class; unit tests with mocked services would not.
**Alternative**: a unit-test layer over the services with mocked dependencies.
**Trade-off**: easier — tests exercise real behaviour through real HTTP with a real database, so a
green suite means something. Harder — slower, and a failure points at an endpoint rather than a
function. Some pure logic (the weighted-average formula, `LocalDateRange`'s conversions) would be
cheaper and clearer to test in isolation.

Defensible for this codebase. `LocalDateRange` in particular is a pure function library and is the
obvious first candidate if unit tests are ever added.

### B10. 📌 Pessimistic locking for concurrent money, nothing for concurrent stock

**What was chosen**: `lockForUpdate()` throughout `PaymentService` and `LedgerService::issueRefund`,
with detailed comments explaining the MySQL REPEATABLE READ snapshot reasoning. **No locking at all**
between `checkStock` and `deductStock`.
**Why the money side** ✅: two concurrent payments on the same order would both read
`totalAlreadyPaid = 0` and both apply in full, overpaying. The comments show this was reasoned
through carefully — including the subtle point that a *plain* read after a lock reuses the pinned
snapshot, which is why the locking reads have to be locking reads.
**Why the stock side has none** ❓: I could not determine this from the code. It may be an oversight,
or it may be a judgement that `unsignedInteger` is a sufficient backstop and stock oversell is
recoverable where overpayment isn't.
**Trade-off**: the money path is genuinely correct under concurrency. The stock path relies on the
column type to fail loudly rather than on a lock to prevent the race — the second concurrent
`decrement` errors out instead of the second order being cleanly rejected. See
[CODEBASE_NOTES.md](CODEBASE_NOTES.md) #4.

⚠️ `SupplierPaymentService` uses no locking either, despite doing the same
"read what's owed, then write" pattern as `PaymentService`. Inconsistent with B10's own reasoning.

### B11. 📌 Batch IDs for activity grouping

**What was chosen**: a UUID `batch_id` on `inventory_transactions`, set by every multi-line
operation, so the activity feed groups a 5-product sale into one row.
**Why**: a merchant reading their activity feed wants "sold 5 items to Ahmed", not five rows.
**Alternative rejected** 🔍: grouping by `(reference_type, reference_id, created_at)` in the query —
fragile, and it can't distinguish two edits to the same order seconds apart.
**Trade-off**: easier — one indexed column makes grouping trivial (`COALESCE(batch_id, id)`) and
degrades gracefully for rows written before the column existed. Harder — every new multi-line
operation must remember to generate and pass one.

⚠️ **It only covers inventory.** There is no correlation ID spanning `ledger_entries`,
`inventory_transactions` and `audit_logs`, so "show me everything this one API call did" is not
answerable. That's the natural next step for this decision.

---

## Decisions I could not determine ❓

Honesty section — things that look like decisions but where the code doesn't tell me the reasoning:

1. **No token expiry on Sanctum tokens.** No `expiration` configured. Deliberate for merchant
   convenience, or an oversight?
2. **`users.email` unique globally, not per tenant.** The same person cannot hold accounts at two
   businesses. Nothing suggests this was considered.
3. **Purchase orders have no business date.** Orders have `order_date` and can be backdated; POs use
   `created_at` only. Whether merchants need to backdate a delivery is a product question.
4. **No queues.** `QUEUE_CONNECTION=database` is configured and the `jobs` table exists, but nothing
   dispatches. Groundwork, or leftover scaffolding?
5. **`Product::booted()` deletes inventory rows** while `ProductObserver::deleting` handles the other
   guards. Why two different mechanisms on the same model?

---

**Related documents**: the authoritative ADRs in
[01-architecture/decisions/](01-architecture/decisions/), [DOMAIN_RULES.md](DOMAIN_RULES.md),
[CODEBASE_NOTES.md](CODEBASE_NOTES.md).
**Future improvements**: promote B4 (timezones) to ADR-009 and B2 (defence-in-depth tenant
isolation) to ADR-010 — both are load-bearing and easy to violate accidentally. Correct ADR-005 and
ADR-007 where they overstate the implementation.
**Open questions**: the five listed above.
**Last review checklist**: [ ] Part A matches the ADR files, [ ] Part B still reflects the code,
[ ] undocumented decisions promoted or still listed. Last reviewed: 2026-08-30.
