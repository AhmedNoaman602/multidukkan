# MultiDukkan — Manual QA Checklist

**What this is**: the twelve end-to-end flows that automated tests cannot cover, with the exact
database state each one should produce. Work through it before a release, or when you want to
*understand* a feature rather than read about it.

**What this is not**: a re-test of the backend. `php artisan test` covers ~298 cases — balances,
FIFO, tenant isolation, stock deduction, timezone boundaries. Run it first. **Never manually re-test
what's already green**; that is what makes manual QA feel infinite.

Your real QA surface is only three things:
1. The React frontend talking to the API (zero automated coverage)
2. Multi-step journeys that cross features
3. Whether the numbers on screen make sense to a shopkeeper

---

## The three rules

1. **Do not fix bugs as you find them.** Log them in [the bug log](#bug-log) at the bottom, finish
   the flow, triage afterwards. Break this rule and flow 1 becomes a three-hour debugging session
   and flows 2–12 never happen.
2. **Watch it in Telescope.** `/telescope` is already enabled in local. Click a button, then read the
   request: every query, every model, in order. This is how the connections between features become
   obvious — you watch one order fan out into eight tables and it clicks permanently.
3. **Check the tables, not just the screen.** The screen shows the result; the tables show the
   mechanism. Run [the flow tracer](#the-flow-tracer) after every action.

**Session shape**: ~50 min per flow. 10 min reading the linked doc section, 30 min driving the flow,
10 min writing down what broke. Two flows per sitting is a good day.

**Priority**: flows **4, 6, 7, 8** are the money paths and the biggest understanding gap. If you only
ever do four, do those.

---

## Before you start

```bash
php artisan test              # must be green — this is your baseline
```

Working tree must be clean (`git status`). Otherwise a half-finished edit will look like a bug.

Find your tenant id and keep it handy:

```sql
SELECT id, name FROM tenants;
```

### The flow tracer

The single most useful query in this document. Run it after **every** action — it shows what the app
actually did, across all three history tables, newest first.

```sql
SET @t := 1;   -- your tenant_id

SELECT 'ledger'    AS src, id, type   AS what, amount, NULL     AS qty, reference_type, reference_id, created_at
  FROM ledger_entries         WHERE tenant_id = @t
UNION ALL
SELECT 'inventory' AS src, id, type   AS what, NULL,   quantity AS qty, reference_type, reference_id, created_at
  FROM inventory_transactions WHERE tenant_id = @t
UNION ALL
SELECT 'audit'     AS src, id, action AS what, NULL,   NULL     AS qty, auditable_type, auditable_id, created_at
  FROM audit_logs             WHERE tenant_id = @t
ORDER BY created_at DESC
LIMIT 25;
```

Current state of one order, in one go:

```sql
SET @o := 1;   -- order id

SELECT * FROM orders WHERE id = @o;
SELECT id, product_name, quantity, unit_type, unit_price, warehouse_id FROM order_items WHERE order_id = @o;
SELECT id, amount, refunded_amount, method, is_auto_reversible FROM payments WHERE order_id = @o;
SELECT id, type, amount, description FROM ledger_entries WHERE reference_type IN ('order','payment') ORDER BY id DESC LIMIT 10;
```

Stock for one product:

```sql
SELECT w.name, i.quantity, i.threshold
  FROM inventory i JOIN warehouses w ON w.id = i.warehouse_id
 WHERE i.product_id = 1;
```

---

## Flow 1 — Register → onboarding → store → warehouse

**Read first**: [DEVELOPER_GUIDE §4 Tenants](DEVELOPER_GUIDE.md), §10 Security

- [ ] `POST /register` via the UI with a new business name + email
- [ ] You land on `/onboarding` (not the dashboard)
- [ ] Create a store — you now reach `/dashboard`
- [ ] Create a warehouse inside that store
- [ ] Log out, log back in — you go straight to the dashboard, **not** back to onboarding

**Expected DB state after registering:**

| Table | Rows | Notes |
|---|---|---|
| `tenants` | 1 | |
| `users` | 1 | `role = tenant_admin`, `store_id = NULL` |
| `units` | 9 | Arabic defaults: حبة، متر، كيلو، علبة، لفة، طن، لتر، كرتونة، رول |
| `customers` | 1 | `is_walk_in = 1`, name `زبون نقدي` |

```sql
SELECT role, store_id FROM users WHERE tenant_id = @t;
SELECT COUNT(*) FROM units WHERE tenant_id = @t;                       -- 9
SELECT id, name, is_walk_in FROM customers WHERE tenant_id = @t;       -- the walk-in
```

**Why the re-login check matters**: `AuthGate` calls `GET /me` on every route change and redirects
admins with `has_store = false` to onboarding. An earlier version read stale `localStorage` and
bounced existing admins back to onboarding incorrectly.

**Recall**: why is the walk-in customer created at registration rather than on first Quick Sale?

---

## Flow 2 — Create a product

**Read first**: [DEVELOPER_GUIDE §4 Products & units](DEVELOPER_GUIDE.md),
[DOMAIN_RULES §6](DOMAIN_RULES.md)

- [ ] Create a simple product: name, SKU, `price`
- [ ] Create one with **all six prices** (`price` + `price_a`…`price_e`)
- [ ] Create one with **dual units**: `unit = pcs`, `secondary_unit = box`, `conversion_factor = 12`
- [ ] Create one with `opening_quantity = 25` and no warehouse selected
- [ ] Create one with `stocks[]` — pick a warehouse, set quantity and threshold
- [ ] Attach 2 suppliers via the multi-select
- [ ] Edit the product: change the price, remove one supplier, change stock quantity
- [ ] Try to reuse an existing SKU → must be rejected (unique per tenant)

**Expected:**

| Check | Expected |
|---|---|
| `products.cost_price` after create | Whatever you typed, or `NULL`. Never auto-computed here. |
| `opening_quantity = 25`, no warehouse | Stock lands in the tenant's **first warehouse by id** |
| `supplier_products` rows | 2, both with your `tenant_id` stamped server-side |
| After removing 1 supplier | 1 row — `syncSuppliers` detaches what you left out |
| `ProductResource.profit_margin` | `price − cost_price`, `NULL` when `cost_price` is `NULL` |

⚠️ **Known gap — expect this, don't log it as new**: opening stock and `stocks[]` at *create* time
write straight to `inventory` with **no `inventory_transactions` row**
([CODEBASE_NOTES #6](CODEBASE_NOTES.md)). Editing stock later *does* log correctly. So:

```sql
-- after CREATE with opening_quantity: expect 0 rows
SELECT * FROM inventory_transactions WHERE product_id = <new id>;

-- after EDITING the stock quantity: expect an ADJUSTMENT_IN / ADJUSTMENT_OUT row
SELECT type, quantity, notes, batch_id FROM inventory_transactions WHERE product_id = <new id>;
```

**Recall**: what's the difference between `products.cost_price` and `supplier_products.cost_price`?

---

## Flow 3 — Create a customer

**Read first**: [DEVELOPER_GUIDE §4 Customers](DEVELOPER_GUIDE.md)

- [ ] Create a customer with no tier → check `price_tier`
- [ ] Create one with tier `b`
- [ ] Confirm the auto-generated `code` follows `C-001`, `C-002`, …
- [ ] Confirm the walk-in customer does **not** appear in the customers list
- [ ] Try to delete a customer who has an order → must be blocked

```sql
SELECT id, code, name, price_tier, is_walk_in FROM customers WHERE tenant_id = @t;
```

**Expected**: delete is blocked by `CustomerObserver::deleting` if the customer has **any** order
(including cancelled ones), any payment, or a non-zero balance. Message comes back in Arabic.

---

## Flow 4 — Normal order ⭐ the keystone

**Read first**: [DEVELOPER_GUIDE §5.1](DEVELOPER_GUIDE.md) and [§6](DEVELOPER_GUIDE.md)

### 4a. Plain order, customer with zero balance

**Predict the DB state before you look.** Then:

- [ ] Create an order: 2 line items, both base units, warehouse selected, no discount, no immediate
      payment

| Table | Expected rows | Detail |
|---|---|---|
| `orders` | 1 | `total` = sum of lines; `invoice_number` = `YYYY-NNN` |
| `order_items` | 2 | `product_name` + `unit_price` **snapshotted** |
| `inventory` | 2 updated | reduced by the line quantities |
| `inventory_transactions` | 2 | `type = SALE`, **same `batch_id`** on both |
| `ledger_entries` | **1** | `ORDER_CHARGE` only |
| `payments` | **0** | |
| `audit_logs` | **0** | ← the surprising one |

**Why zero audit rows**: `OrderObserver::updated` returns early when `wasRecentlyCreated` is true.
Creating an order is an insert-then-fill (header, then `update(['total' => …])`), and that second
write is part of the creation moment already owned by the `ORDER_CHARGE` entry.

- [ ] Run the flow tracer — confirm one `ledger` row and one grouped `inventory` event
- [ ] Open `/audit-log` — the 2-item sale shows as **one** row with `item_count: 2`, expandable

### 4b. Secondary units

- [ ] Order **3 boxes** of the `conversion_factor = 12` product

| Check | Expected |
|---|---|
| `order_items.quantity` | `3` |
| `order_items.unit_type` | `secondary` |
| `order_items.unit_price` | tier price **× 12** |
| `inventory` reduction | **36**, not 3 |
| `inventory_transactions.quantity` | `36` |

This is the invariant that matters most: **stock is always stored in base units.**

### 4c. `manual_total`

- [ ] Create an order whose lines sum to 500, override the total to 450

| Check | Expected |
|---|---|
| `orders.total` | `450` |
| `ORDER_CHARGE` amount | `450` |
| `OrderResource.subtotal` | `500` (sum of lines — they legitimately differ) |

- [ ] Now **edit a line item** on that order

⚠️ **Expect `manual_total` to be lost** — `recalculateTotal()` recomputes from
`SUM(unit_price × quantity) − discount`. Confirmed and tested behaviour, not a bug.

### 4d. Editing items 🔴 known bug

- [ ] On the **secondary-unit** order from 4b, change the quantity from 3 boxes to 4

| | |
|---|---|
| Should deduct | **12** more base units |
| Actually deducts | **1** |

This is [CODEBASE_NOTES #1](CODEBASE_NOTES.md) — `adjustItem` doesn't convert by `unit_type`. Confirm
it with your own eyes, then leave it; it's already logged.

```sql
SELECT quantity FROM inventory WHERE product_id = <id> AND warehouse_id = <id>;
```

### 4e. Edit locks

- [ ] Pay part of an order, then try to edit an item **as `store_staff`** → blocked (422)
- [ ] Same edit as `store_manager` → allowed
- [ ] Fully settle an order, then try to edit → blocked for **everyone**

**Recall**: why is stock aggregated by `product + warehouse` for the check, but merged by
`product + warehouse + unit_type` for the rows?

---

## Flow 5 — Quick Sale

**Read first**: [DEVELOPER_GUIDE §5.2](DEVELOPER_GUIDE.md)

- [ ] Open Quick Sale from the orders page, add 2 products, complete the sale

**The point of this flow**: it hits the **same** `POST /orders` endpoint as flow 4, with two extra
fields (`pay_immediately: true`, `payment_method: 'cash'`) and the walk-in customer. Watch it in
Telescope and confirm that for yourself — there is no separate Quick Sale backend.

**Difference from flow 4a** — exactly two additional rows:

| Table | Extra |
|---|---|
| `payments` | 1 — `method = cash`, `is_auto_reversible = 0` |
| `ledger_entries` | 1 — `PAYMENT` |

- [ ] Order shows `status: paid` immediately
- [ ] Try to edit it → blocked (it's fully settled)
- [ ] Confirm it does **not** appear in the dashboard's `total_owed` or the debtor list (walk-in
      customers are excluded)

---

## Flow 6 — Payments ⭐

**Read first**: [DEVELOPER_GUIDE §5.4](DEVELOPER_GUIDE.md), [DOMAIN_RULES §5](DOMAIN_RULES.md)

### 6a. Direct partial payment

- [ ] Order of 500. Pay 200 against it.

| Check | Expected |
|---|---|
| `payments` | 1 row, `amount = 200` |
| `ledger_entries` | 1 `PAYMENT` for 200 |
| `getBalance` (`/customers/{id}/balance`) | `300` |
| Order status | `unpaid`, `amount_remaining = 300` |

- [ ] Pay the remaining 300 → status flips to `paid`
- [ ] Try to pay again → 422 "already fully paid"

### 6b. Overpayment → FIFO → credit

Set up **three** unpaid orders for one customer: 200, 150, 100 (create them in that order).

- [ ] Pay **600** against the *newest* order (the 100 one)

Expected distribution — the excess walks the customer's other unpaid orders **oldest
`created_at` first**:

| Order | Created | Owed | Paid by this transaction |
|---|---|---|---|
| A | 1st | 200 | 200 |
| B | 2nd | 150 | 150 |
| C | 3rd | 100 | 100 ← the one you targeted |
| leftover | | | **150 → `CREDIT_APPLY`** |

```sql
SELECT order_id, amount, method FROM payments WHERE customer_id = <id> ORDER BY id;
SELECT type, amount, description FROM ledger_entries WHERE customer_id = <id> ORDER BY id;
```

- [ ] Confirm 3 `payments` rows + 3 `PAYMENT` entries + 1 `CREDIT_APPLY` for 150
- [ ] `/customers/{id}/balance` → `-150` (negative = they're in credit)

⚠️ Note FIFO orders by **`created_at`**, not `order_date`. A backdated order does not jump the queue.

### 6c. Auto payment

- [ ] With fresh unpaid orders, use auto-payment (no order named) for an amount covering 1.5 orders
- [ ] Confirm oldest-first allocation, and that the partial order gets exactly what it owed

### 6d. Credit spent on the next order

- [ ] With that 150 credit sitting there, create a new order for 400

| Table | Expected |
|---|---|
| `payments` | 1 — `method = credit`, `is_auto_reversible = 1`, `amount = 150` |
| `ledger_entries` | 3 — `ORDER_CHARGE` 400, `PAYMENT` 150, `CREDIT_CONSUMED` 150 |
| Balance | `250` |
| `/customers/{id}/summary` credit | `0` |

**Recall**: why does spending credit write **two** entries? What breaks with only `PAYMENT`?

⚠️ **Known inconsistency — expect it**: the orders-list `unpaid_amount` stat uses `cashOnly()`, so an
order settled with store credit still counts toward it, while the dashboard's unpaid count doesn't
([CODEBASE_NOTES #8](CODEBASE_NOTES.md)).

---

## Flow 7 — Refunds ⭐

**Read first**: [DEVELOPER_GUIDE §5.4](DEVELOPER_GUIDE.md)

### 7a. Payment-level refund

- [ ] Order 500, pay 300 cash. Refund 100 from that specific payment.

| Check | Expected |
|---|---|
| `payments.refunded_amount` | `100` (row is **not** deleted) |
| `ledger_entries` | 1 `REFUND` for 100, `reference_type = 'payment'` |
| Balance | `300` — the refund is a **debit** |

- [ ] Try to refund another 250 from the same payment → rejected (only 200 refundable)

### 7b. Order-level FIFO refund

- [ ] Order with three cash payments: 100, 100, 100. Refund 250 at the order level.

Expected distribution across payments by ascending `id`:

| Payment | `refunded_amount` after |
|---|---|
| 1st | 100 |
| 2nd | 100 |
| 3rd | 50 |

- [ ] Confirm exactly **one** `REFUND` entry for 250, `reference_type = 'order'`

### 7c. Credit is never cash-refundable

- [ ] Try to refund a payment where `is_auto_reversible = 1` → must be rejected
- [ ] Confirm the order-level refundable cap **excludes** credit payments

**Why**: store credit moved no cash. Refunding it as cash would hand out money that was never
received. Cancel the order instead.

**Recall**: why is `REFUND` a debit rather than a credit?

---

## Flow 8 — Cancelling an order ⭐

**Read first**: [DEVELOPER_GUIDE §5.5](DEVELOPER_GUIDE.md)

### 8a. Blocked by unrefunded cash

- [ ] Order with a cash payment on it → try to cancel → **blocked**, message says refund first
- [ ] Refund the payment fully → cancel now succeeds

### 8b. Clean cancel

- [ ] Order with no payments, 2 line items, one with secondary units → cancel

| Table | Expected |
|---|---|
| `orders.deleted_at` | set (soft delete) |
| `inventory` | restored — secondary line restored at **× conversion_factor** |
| `inventory_transactions` | 2 new `RETURN` rows, same `batch_id` |
| `ledger_entries` | 1 `REVERSAL` for the full total |
| Balance | back to where it was |

### 8c. Cancel an order paid with credit — the interesting one

- [ ] Give a customer 100 credit, create an order for exactly 100 (credit auto-applies), then cancel

Trace the whole story:

```sql
SELECT id, type, amount, description FROM ledger_entries WHERE customer_id = <id> ORDER BY id;
```

| # | Type | Debit | Credit |
|---|---|---|---|
| 1 | `CREDIT_APPLY` (original) | | 100 |
| 2 | `ORDER_CHARGE` | 100 | |
| 3 | `PAYMENT` | | 100 |
| 4 | `CREDIT_CONSUMED` | 100 | |
| 5 | `CREDIT_APPLY` (restored) | | 100 |
| | **Totals** | **200** | **300** |

- [ ] Balance = `−100` → the customer has their credit back ✅
- [ ] **No `REVERSAL` row** — because `total − creditPaymentsTotal = 0`
- [ ] The credit `payments` row is **gone** (hard-deleted), but entry #3 remains

That last point looks wrong and is deliberate: credit payment rows are bookkeeping artifacts; the
ledger entries are what balances depend on.

---

## Flow 9 — Purchase order

**Read first**: [DEVELOPER_GUIDE §5.3](DEVELOPER_GUIDE.md), [ADR-008](01-architecture/decisions/ADR-008-weighted-average-costing.md)

- [ ] Product with 60 units in stock at `cost_price = 9`
- [ ] Create a PO: 10 **boxes** (factor 12) at 120 per box, into a warehouse

| Check | Expected |
|---|---|
| `purchase_order_items.unit_price` | `120` — as invoiced, per box |
| `inventory` increase | `120` base units |
| `inventory_transactions` | `PURCHASE_IN`, quantity `120` |
| `products.cost_price` | `(60×9 + 120×10) / 180 = 9.67` |
| `supplier_products.last_purchase_price` | `10` — **per base unit**, not 120 |
| `supplier_products.last_purchased_at` | today, shop's calendar |
| `ledger_entries` | `PURCHASE_CHARGE`, `direction = debit` |

- [ ] Receive a product into a warehouse it has **never** been stocked in → an `inventory` row is
      created at 0 first (`ensureStockRow`), then filled
- [ ] Two lines for the **same** product in one PO → the second averages against the first, not
      against the pre-PO stock

```sql
SELECT cost_price FROM products WHERE id = <id>;
SELECT last_purchase_price, last_purchased_at FROM supplier_products WHERE product_id = <id>;
```

- [ ] Cancel the PO → stock down via `PURCHASE_OUT`, `PURCHASE_REVERSAL` posted, PO soft-deleted

⚠️ **Expect this**: `products.cost_price` is **not** rolled back on cancel. A cancelled PO
permanently influences the average. Confirmed behaviour — flagged in
[ARCHITECTURE_DECISIONS A8](ARCHITECTURE_DECISIONS.md) as an open question.

**Recall**: why does the average use stock across **all** warehouses rather than the receiving one?

---

## Flow 10 — Supplier payments

- [ ] Three unpaid POs for one supplier. Pay an amount covering 1.5 of them, **without** naming a PO
- [ ] Confirm FIFO by `created_at`, oldest first
- [ ] Try to pay **more** than the supplier is owed → rejected (no supplier credit concept exists)
- [ ] Pay a specific PO by naming it → capped at what that PO owes
- [ ] Reverse a payment → `SUPPLIER_PAYMENT_REVERSAL` posted, payment row **hard-deleted**, both
      ledger entries remain
- [ ] Try to reverse the same payment twice → rejected
- [ ] Try to cancel a PO that still has a payment → blocked; reverse the payment first, then it works

```sql
SELECT type, direction, amount FROM ledger_entries
 WHERE entity_type = 'supplier' AND entity_id = <supplier id> ORDER BY id;
```

Note the supplier side uses `direction` (`debit`/`credit`), not the customer side's `type`-implied
direction. Two schemas in one table — deliberate, see [DOMAIN_RULES §2](DOMAIN_RULES.md).

---

## Flow 11 — Dashboard, report, audit log

- [ ] Dashboard with `period = today` / `week` / `month` / `year` — figures change sensibly
- [ ] Dashboard `total_owed` **excludes** the walk-in customer
- [ ] Low-stock list shows only `quantity <= threshold` **and** `quantity > 0`
- [ ] Low-stock **count** is not capped by the 3-row preview
- [ ] Daily report: `net_profit = gross_profit − expenses`
- [ ] Add an expense inside the range → `net_profit` drops by exactly that amount
- [ ] Report returns `business_timezone` in its payload
- [ ] `missing_cost_prices` counts distinct products with `NULL` cost
- [ ] Audit log: a multi-item sale is **one** row; opening the drawer lists each product
- [ ] Audit log: editing a customer's phone shows `changes` as `{phone: [old, new]}`
- [ ] Audit log filters by the **shop's** calendar day, not yours

**Timezone check worth doing once**: change your machine's timezone (or send a different
`X-Timezone`), reload the report — **totals must be identical**. The daily report deliberately
ignores the viewer's zone.

---

## Flow 12 — Roles

**Read first**: [DEVELOPER_GUIDE §10](DEVELOPER_GUIDE.md)

Create a `store_manager` and a `store_staff` bound to one store. Log in as each.

| Action | admin | manager | staff |
|---|---|---|---|
| Create product | ✅ | ❌ | ❌ |
| Create supplier / PO / supplier payment | ✅ | ❌ | ❌ |
| Link products ↔ suppliers | ✅ | ❌ | ❌ |
| Create warehouse | ✅ | ✅ | ❌ |
| Adjust inventory | ✅ | own store | ❌ |
| Create order / payment / customer | ✅ | ✅ | ✅ |
| Cancel order | ✅ | ✅ | ❌ |
| Edit a partially-paid order | ✅ | ✅ | ❌ |
| Delete customer | ✅ | ❌ | ❌ |
| Add customer credit | ✅ | ✅ | ❌ |
| Expenses (any) | ✅ | own store, own rows | ❌ |
| Audit log | ✅ | ❌ | ❌ |
| Dashboard financials | ✅ | ✅ | ❌ fields absent |

- [ ] As staff, confirm revenue/debt/debtor fields are **absent from the payload**, not just hidden
      in the UI (check the network tab)
- [ ] As manager, confirm the orders list and dashboard show **only your store**
- [ ] As manager, try to adjust another store's inventory → 403

**The check that matters most**: pick any action a role shouldn't have and confirm the **API** refuses
it, not just that the button is hidden. A hidden button is not a permission.

---

## Bug log

Fill this in as you go. Don't fix anything until the flow is finished.

| # | Flow | What you did | Expected | Actual | Severity | Fixed? |
|---|---|---|---|---|---|---|
| | | | | | | |

**Already known — don't re-log these:**

| Known issue | Where |
|---|---|
| `adjustItem` ignores `unit_type` when moving stock | [CODEBASE_NOTES #1](CODEBASE_NOTES.md) |
| Opening stock writes no `inventory_transactions` row | [CODEBASE_NOTES #6](CODEBASE_NOTES.md) |
| Orders-list `unpaid_amount` ignores credit payments | [CODEBASE_NOTES #8](CODEBASE_NOTES.md) |
| `cost_price` not rolled back on PO cancel | [ARCHITECTURE_DECISIONS A8](ARCHITECTURE_DECISIONS.md) |
| `manual_total` lost on a later item edit | Tested, intentional |
| Product list N+1 on `inventories.warehouse` | [CODEBASE_NOTES #7](CODEBASE_NOTES.md) |

---

## Progress

| Flow | Done | Date | Bugs found |
|---|---|---|---|
| 1 Register → warehouse | ☐ | | |
| 2 Product | ☐ | | |
| 3 Customer | ☐ | | |
| **4 Order** ⭐ | ☐ | | |
| 5 Quick Sale | ☐ | | |
| **6 Payments** ⭐ | ☐ | | |
| **7 Refunds** ⭐ | ☐ | | |
| **8 Cancel** ⭐ | ☐ | | |
| 9 Purchase order | ☐ | | |
| 10 Supplier payments | ☐ | | |
| 11 Dashboard / report / audit | ☐ | | |
| 12 Roles | ☐ | | |

---

**Related documents**: [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md) for the flows behind each check,
[DOMAIN_RULES.md](DOMAIN_RULES.md) for the invariants, [CODEBASE_NOTES.md](CODEBASE_NOTES.md) for
known issues, [LEARNING_PATH.md](LEARNING_PATH.md) if you want the study version of this.
**Future improvements**: add a stock-transfer flow when Phase 3 lands; automate flows 4/6/7/8 as
browser tests if manual repetition becomes a chore.
**Last reviewed**: 2026-09-03.
