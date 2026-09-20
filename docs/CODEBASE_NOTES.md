# MultiDukkan — Codebase Notes

Confusing areas, duplicated logic, suspicious implementations and inconsistent patterns, found by
reading the code on 2026-08-30. **Nothing here has been changed.** This document exists so you
recognise these when you hit them, and so nobody "fixes" something that is deliberate.

**Severity**: 🔴 High (can corrupt data or money) · 🟠 Medium (wrong output, perf, or a real trap) ·
🟢 Low (cosmetic, stale, or merely confusing).

**Read [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md) first** if these findings don't have context yet.

---

## Summary table

| # | Finding | Severity | File |
|---|---|---|---|
| 1 | `adjustItem` ignores `unit_type` when moving stock | 🔴 | `OrderService.php:344` |
| 2 | `adjustOrderCharge` writes two rows outside a transaction | 🔴 | `LedgerService.php:529` |
| 3 | PO invoice numbering ignores soft-deleted POs | 🟠 | `PurchaseOrderService.php:31` |
| 4 | No lock between `checkStock` and `deductStock` | 🟠 | `InventoryService.php:18` |
| 5 | `adjustItem` has no null-warehouse branch | 🟠 | `OrderService.php:355` |
| 6 | Opening stock writes `inventory` with no transaction row | 🟠 | `ProductService.php:48` |
| 7 | `ProductResource` N+1 on the product list | 🟠 | `ProductController.php:30` |
| 8 | Two definitions of "unpaid" in the order feature | 🟠 | `OrderController.php:68` |
| 9 | `PaymentController::index` unpaginated and un-Resourced | 🟠 | `PaymentController.php:18` |
| 10 | Several other unpaginated full-table reads | 🟠 | multiple |
| 11 | Balance type lists duplicated in two methods | 🟠 | `LedgerService.php:186,207` |
| 12 | `SupplierPaymentService` has no locking | 🟠 | `SupplierPaymentService.php:17` |
| 13 | Read paths bypass the service layer entirely | 🟠 | 4 controllers |
| 14 | Refund validation implemented twice | 🟢 | `RefundCustomerRequest` / `LedgerService` |
| 15 | Order-cancel guard implemented twice | 🟢 | `OrderObserver` / `OrderService` |
| 16 | Price-tier `match` duplicated | 🟢 | `OrderService.php:121,434` |
| 17 | Product stock guard duplicated | 🟢 | `ProductController` / `ProductObserver` |
| 18 | Three error-extraction styles in one controller | 🟢 | `OrderController.php` |
| 19 | `ProductController::update` / `WarehouseController` bypass `validated()` | 🟢 | 2 controllers |
| 20 | `LedgerEntry::TYPES` is stale dead code | 🟢 | `LedgerEntry.php` |
| 21 | Dead `$otherPaymentsTotal` in `adjustPayment` | 🟢 | `LedgerService.php:501` |
| 22 | `withTrashed()` on routes for non-soft-deleting models | 🟢 | `routes/api.php:74,81` |
| 23 | `PurchaseOrderController::index` uses MySQL-only `YEAR()` | 🟢 | `PurchaseOrderController.php:24` |
| 24 | `orders.discount` stored unclamped | 🟢 | `OrderService.php:182,244` |
| 25 | Docs contradict the code in four places | 🟢 | `docs/` |
| — | Product / warehouse deletion cascades away history | 🔴 | see [DOMAIN_RULES.md](DOMAIN_RULES.md) §7 |

---

## 🔴 High

### 1. `OrderService::adjustItem` ignores `unit_type` when moving stock

**File**: [`app/Services/OrderService.php:344`](../app/Services/OrderService.php)

```php
$oldQty = $item->quantity;
$newQty = $data['quantity'] ?? $oldQty;
$delta  = $newQty - $oldQty;

if ($delta > 0) {
    $this->inventory->checkStock($item->product_id, $item->warehouse_id, $delta);
    $this->inventory->deductStock($item->product_id, $item->warehouse_id, $delta, …);
} elseif ($delta < 0) {
    $this->inventory->restoreStock($item->product_id, $item->warehouse_id, abs($delta), …);
}
```

**The problem**: `$delta` is in the **line item's** units. `InventoryService` expects **base** units.
Every other caller converts first — `createOrderAttempt` (line 116), `addItem` (line 428),
`cancelOrder` (line 547) all check `$item->unit_type === 'secondary' && $product->conversion_factor`.
`adjustItem` does not.

**Concrete failure**: a product with `conversion_factor = 12`. A line for 2 boxes is 24 base units on
the shelf. Editing the line to 3 boxes should deduct 12 more. It deducts **1**. The order says 3
boxes; the warehouse thinks 25 units left. Every subsequent stock number for that product is wrong,
and nothing surfaces the discrepancy.

**Why it survived**: no test targets `adjustItem`'s stock effects. `RoleTest` exercises the
*permission* around editing a partially-paid order, not the arithmetic.

**Note**: this is mistake #5 on the AI collaboration guide's own list of recurring errors — *"Handling
only `base` unit type — secondary lines corrupt stock counts if conversion is skipped."*

---

### 2. `adjustOrderCharge` writes two rows outside a transaction

**File**: [`app/Services/LedgerService.php:529`](../app/Services/LedgerService.php)

```php
public function adjustOrderCharge(Order $order, float $newTotal): void
{
    LedgerEntry::where('reference_type', 'order')
        ->where('reference_id', $order->id)
        ->where('type', 'ORDER_CHARGE')
        ->update(['amount' => $newTotal]);

    $order->update(['total' => $newTotal]);   // ← second write, no transaction
}
```

**The problem**: ADR-004's central invariant is *`orders.total` equals the `ORDER_CHARGE` entry's
amount, always*. This method is the only thing that keeps them together — and it does two separate
writes with no transaction of its own.

**When it's safe**: called from `adjustItem` (line 374) and `addItem` (line 473), both already inside
`DB::transaction`.

**When it isn't**: called from `updateOrder` (line 490), which has **no transaction wrapper**:

```php
public function updateOrder(Order $order, array $data): Order
{
    if (isset($data['discount'])) $this->ensureOrderIsEditable($order);
    $order->update($data);
    if (isset($data['discount'])) {
        $this->ledger->adjustOrderCharge($order, $this->recalculateTotal($order));
    }
    return $order->load(…);
}
```

A `PATCH /orders/{id}` changing the discount does three unwrapped writes. A failure between them
leaves the ledger and `orders.total` permanently disagreeing — the exact class of bug ADR-003 exists
to prevent. Note also that this is one of the two *sanctioned* ledger mutations, which makes it the
place where atomicity matters most.

**The fix is one line** — wrap `adjustOrderCharge`'s body in `DB::transaction`. Nested transactions
are safe in Laravel (savepoints), so the existing in-transaction callers are unaffected.

---

## 🟠 Medium

### 3. Purchase-order invoice numbering ignores soft-deleted POs

**File**: [`app/Services/PurchaseOrderService.php:31`](../app/Services/PurchaseOrderService.php)

Compare the two invoice generators:

| | `OrderService` | `PurchaseOrderService` |
|---|---|---|
| Lookup | `Order::withTrashed()` | `PurchaseOrder::` (**no** `withTrashed`) |
| Filter | `invoice_number LIKE "{year}-%"` | `created_at BETWEEN` the year's UTC bounds |

`PurchaseOrder` uses `SoftDeletes`, so the lookup silently excludes cancelled POs — but the UNIQUE
index `(tenant_id, invoice_number)` does **not**.

**Failure**: cancel the most recent PO of the year. The next PO regenerates that same number →
duplicate-key violation → the retry loop runs → the lookup still excludes the trashed row → the same
number again → three failed attempts → the `QueryException` propagates as a 500.

**Confidence**: 🔍 Inferred from reading, not reproduced. The logic is clear, but I did not run it.
`test_invoice_number_rolls_over_past_999_without_collision` exists for both services and tests a
different scenario.

**Fix**: add `->withTrashed()`, matching `OrderService`.

---

### 4. No lock between `checkStock` and `deductStock`

**File**: [`app/Services/InventoryService.php:18`](../app/Services/InventoryService.php)

`checkStock` runs a plain `SELECT`; `deductStock` runs a separate `UPDATE`. Nothing holds a row lock
between them. Two concurrent orders for the last 5 units both pass the check, then both decrement.

**Why it isn't 🔴**: `inventory.quantity` is `UNSIGNED INTEGER`, so the second decrement errors at the
DB rather than going negative, and the transaction rolls back. Stock cannot actually be corrupted —
the user just gets a 500 instead of a clean "insufficient stock" 422.

**Why it's still worth noting**: `PaymentService` solves the identical read-then-write race properly
with `lockForUpdate()`, with detailed comments about MySQL REPEATABLE READ snapshots. The stock path
got none of that attention. See [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) B10.

**Also**: `checkStock` issues **three** queries per call (inventory, product, warehouse) — the last
two only to build the error message, fetched even on the happy path. In a 20-line order that's 60
queries where 20 would do.

---

### 5. `adjustItem` has no null-warehouse branch

**File**: [`app/Services/OrderService.php:355`](../app/Services/OrderService.php)

Every other stock path is wrapped in `if ($warehouseId)` (ADR-007). `adjustItem` calls
`checkStock($item->product_id, $item->warehouse_id, $delta)` unconditionally, and `checkStock`'s
signature is `int $warehouseId`. A legacy line item with `warehouse_id = null` throws a `TypeError`
→ 500.

**Reachability**: low today — both order FormRequests mark `warehouse_id` **required**, so new orders
can't have null warehouses. This affects legacy rows only. Listed as recurring AI mistake #4:
*"Forgetting the null-warehouse branch when adding stock-touching code."*

---

### 6. Opening stock writes `inventory` with no transaction row

**File**: [`app/Services/ProductService.php:48`](../app/Services/ProductService.php)

```php
foreach ($data['stocks'] ?? [] as $stock) {
    Inventory::create([... 'quantity' => $stock['quantity'] ?? 0, ...]);   // no log
}
if (!empty($data['opening_quantity'])) {
    $inventory = Inventory::firstOrCreate([...]);
    $inventory->increment('quantity', (int) $data['opening_quantity']);    // no log
}
```

This bypasses `InventoryService` entirely, violating the rule *"never mutate `inventory.quantity`
outside `InventoryService`; every mutation logs a transaction row."*

**Consequence**: stock that arrives at product **creation** is invisible in the activity feed and in
`inventory_transactions`. Stock changed later through `ProductController::update` **is** visible —
that path uses `setStock()`, which logs correctly. So the stock history of a product has a hole at
exactly its starting point, and a merchant auditing "where did these 50 units come from?" finds
nothing.

**Fix**: route both writes through `InventoryService::setStock(...)`, which already does exactly this
job and logs it. `ProductController::update` shows the pattern.

---

### 7. `ProductResource` N+1 on the product list

**Files**: [`ProductController.php:30`](../app/Http/Controllers/Api/V1/ProductController.php),
[`ProductResource.php`](../app/Http/Resources/ProductResource.php)

`ProductController::index` builds the query with no `with()`. `ProductResource::toArray` then does:

```php
'stocks' => $this->inventories->map(fn ($inv) => [
    'warehouse_name' => $inv->warehouse->name,   // ← and a second query per inventory row
    …
]),
```

**Cost**: 15 products per page × (1 inventories query + N warehouse queries). With
`?per_page=all` — which `CreateOrder.jsx` and `QuickSaleModal.jsx` both use — this runs over the
entire catalogue.

**Fix**: `->with('inventories.warehouse')` in `index` (and `show`). One line.

Given that speed is the owner's explicitly stated #1 quality attribute, and that per-row
`getBalance()` N+1 has already shipped once and been fixed (`2abffa7`), this is the same mistake in a
different place.

---

### 8. Two definitions of "unpaid" in the order feature

**File**: [`app/Http/Controllers/Api/V1/OrderController.php:68`](../app/Http/Controllers/Api/V1/OrderController.php)

```php
$totalRevenue = (clone $query)->sum('total');
$paidAmount   = Payment::whereIn('order_id', …)->cashOnly()->sum('amount - refunded_amount');
$unpaidAmount = round($totalRevenue - $paidAmount, 2);
```

`cashOnly()` excludes store-credit payments. But `Order::whereUnpaid()` — used by the dashboard, by
auto-payment, and by `StoreObserver` — counts **all** payments including credit.

**Consequence**: an order fully settled with store credit is `status: 'paid'` in its own row,
excluded from the dashboard's unpaid count, and **still included** in the order list's
`unpaid_amount` statistic. Two screens, two numbers, same orders.

**Which is right?** Depends on the question. "How much are we still owed?" should include credit
payments (the debt is settled). "How much cash have we not yet collected?" shouldn't. The stat is
labelled `unpaid_amount`, which reads as the first. 🔍

**This is the same shape of bug ADR-003 was written about** — two places independently deciding what
"owed" means — just in a statistic rather than a balance.

---

### 9. `PaymentController::index` is unpaginated and returns raw models

**File**: [`app/Http/Controllers/Api/V1/PaymentController.php:18`](../app/Http/Controllers/Api/V1/PaymentController.php)

```php
$payments = Payment::where('tenant_id', …)->…->get();   // no paginate()
return response()->json(['data' => $payments, 'total' => …, 'count' => …]);
```

Violates two standing rules at once: *"paginate every list"* and *"API Resources for all responses —
never return raw models."*

**Consequences**: unbounded memory and payload growth with payment volume; and because it's a raw
model, every column ships to the client and any future column is exposed automatically. There is no
`PaymentResource` in `app/Http/Resources/`.

**Mitigation in place**: a date filter, which the frontend always sends. That bounds it in practice
but not by contract.

---

### 10. Other unpaginated full-table reads

| Location | Query |
|---|---|
| `SupplierController::summary` | all products + all POs + all payments for a supplier |
| `SupplierController::products` | `$supplier->products()->with('inventories')->get()` |
| `LedgerEntryController::summary` | all of a customer's orders **and** all their payments |
| `LedgerService::getHistory` | the customer's or supplier's entire ledger, unbounded |
| `WarehouseController::index` | all warehouses (bounded in practice — few per tenant 🟢) |
| `UserController::index` | all users (same 🟢) |

The first four grow without limit for an active customer or supplier. `getHistory` is the sharpest:
a customer with three years of trading returns every ledger row in one response, and it is called by
three separate endpoints (`history`, `summary`, `supplierHistory`).

---

### 11. Balance type lists duplicated in two methods

**File**: [`app/Services/LedgerService.php:186` and `:207`](../app/Services/LedgerService.php)

```php
// getBalance()
->whereIn('type', ['ORDER_CHARGE', 'CREDIT_CONSUMED', 'REFUND'])   // debits
->whereIn('type', ['PAYMENT', 'CREDIT_APPLY', 'REVERSAL'])         // credits

// getBalancesForCustomers()  — the same two lists, written again
```

Adding a ledger type means editing both. Edit one and balances silently disagree depending on whether
you're looking at a detail page (`getBalance`) or a list (`getBalancesForCustomers`) — **which is
precisely the bug ADR-003 was written about**, reintroduced inside the service that was supposed to
prevent it.

**Fix**: two class constants, `DEBIT_TYPES` and `CREDIT_TYPES`, referenced by both.

---

### 12. `SupplierPaymentService` has no locking

**File**: [`app/Services/SupplierPaymentService.php:17`](../app/Services/SupplierPaymentService.php)

It does the same read-then-write pattern as `PaymentService` — sum what's owed, then write payments —
with no `lockForUpdate()` anywhere on the purchase-order or payment reads. Two concurrent payments to
the same supplier could both see the same "owed" figure and overpay.

`reversePayment` **does** lock, and `test_concurrent_reversal_of_the_same_supplier_payment_is_rejected`
covers it — so the concern was understood on that path and not applied to the payment path.

---

### 13. Read paths bypass the service layer entirely

The architecture rule is "business logic lives in services, controllers stay thin." It holds for
writes. It does not hold for reads:

| Controller | Lines | What's in there |
|---|---|---|
| `ReportController::daily` | 201 | Six aggregations, profit math, pagination-in-PHP — no service |
| `AuditLogController::index` | 216 | A three-way `UNION ALL` in raw SQL, then N lookup queries and display mapping |
| `DashboardController::index` | 178 | Period ranges, six aggregate queries, role-based payload construction |
| `OrderController::index` | ~70 | Query building, year/month derivation, revenue/paid/unpaid stats |

`ReportController` is the sharpest case — it's a 201-line business-logic file sitting in
`app/Http/Controllers/`, and the profit formula it contains
(`(unit_price − cost_price) × quantity`) exists nowhere else, so nothing can reuse or unit-test it.

**Not a bug** — everything works. But if you go looking for "where is gross profit calculated?"
expecting a service, you will not find it.

---

## 🟢 Low

### 14. Refund validation implemented twice

`RefundCustomerRequest::withValidator` and `LedgerService::issueRefund` both check: payment belongs to
customer, payment is not auto-reversible, amount ≤ refundable. The request's version runs first and
produces the user-facing message; the service's version is the real guard (and the one holding the
lock). Both are needed — but they must be changed together, and nothing says so.

⚠️ The request's messages are also **hardcoded English** (`'Credit payments cannot be refunded as
cash…'`) while everything else in the codebase goes through `__('messages.…')` with Arabic first.

### 15. Order-cancel guard implemented twice

`OrderObserver::deleting` and `OrderService::cancelOrder` both run the identical
`cashOnly()->whereRaw('amount > COALESCE(refunded_amount, 0)')->exists()` check, with different
message keys (`order_has_payments` vs `refund_before_cancel`). Defence in depth — the observer catches
any delete path that skips the service — but the duplication is invisible until you change one.

### 16. Price-tier `match` duplicated

The six-arm `match ($customer->price_tier)` block appears identically at
`OrderService.php:121` and `:434`. Adding `price_f` means finding both.

### 17. Product stock guard duplicated

`ProductController::destroy` checks `inventories()->where('quantity','>',0)->exists()` and returns
422; `ProductObserver::deleting` checks the same thing and throws. The controller's check runs first,
so the observer's never fires through the API.

### 18. Three error-extraction styles in one controller

`OrderController` catches `ValidationException` three different ways:

```php
store():     ['message' => collect($e->errors())->flatten()->first()]
update():    ['message' => $e->errors()['order'][0]]           // assumes an 'order' key
destroy():   ['message' => $e->getMessage()]
```

`update()`'s version will throw an undefined-index error if the exception carries any other key.
Cosmetic today because `updateOrder` only ever throws with an `order` key — fragile if that changes.

### 19. Two controllers bypass `validated()`

`ProductController::update` and `WarehouseController::store/update` read `$request->name`,
`$request->price` etc. directly instead of `$request->validated()`. The values are still validated,
but the whitelist the rule set defines is bypassed — against the standing rule *"Always use
`$request->validated()`."*

`ProductController::update` also builds its update array field by field, which is why adding a
product column requires editing that method specifically or the field silently never saves. See
[DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md) §11.

### 20. `LedgerEntry::TYPES` is stale dead code

The const lists 8 types; the DB enum has 10 (`REFUND` and `SUPPLIER_PAYMENT_REVERSAL` were added by
later migrations and never backported). It is referenced **nowhere** in `app/` or `tests/`. Either
delete it or make it the single source and validate against it.

### 21. Dead `$otherPaymentsTotal`

`LedgerService::adjustPayment:501` computes a sum and never uses it. Already on the AI guide's known-
defects list.

### 22. `withTrashed()` on routes for non-soft-deleting models

`routes/api.php:74` and `:81` apply `->withTrashed()` to the product and warehouse delete routes.
Neither model uses `SoftDeletes`, so it's a no-op — but it reads as though they're soft-deleted when
they very much are not, which matters given finding #26 below.

### 23. `PurchaseOrderController::index` uses MySQL-only `YEAR()`

```php
PurchaseOrder::…->selectRaw('YEAR(created_at) as year')
```

`OrderController::index` deliberately derives year/month in PHP with a comment explaining that SQLite
(the test database) has no `YEAR()`/`MONTH()`. `PurchaseOrderController` didn't get the same
treatment. 🔍 Presumably no test exercises that endpoint's `years` field.

### 24. `orders.discount` is stored unclamped

`OrderService.php:182` writes `'discount' => $data['discount'] ?? 0` to the row. Line 244 then clamps
it for the total calculation: `max(0, min($data['discount'] ?? 0, $totalAmount))`. So a discount
larger than the subtotal is stored raw while the total uses the clamped value.

Downstream `recalculateTotal()` re-clamps with `max(0, …)`, so the total never goes negative — but
`OrderResource` returns the raw `discount` to the UI, which will display a discount larger than the
subtotal.

### 25. Docs contradict the code

Three places where following the documentation would mislead you:

| Doc | Claim | Reality |
|---|---|---|
| `09-ai-collaboration/ai-collaboration-guide.md`, divergence table | "PO stock receipts logged as `RETURN` type" | `PurchaseOrderService` passes `TYPE_PURCHASE_IN`/`TYPE_PURCHASE_OUT` explicitly |
| `ADR-005` | `purchase_order_items` snapshots `product_name` | No such column, no such fillable key |
| `ADR-007` | `purchase_order_items.warehouse_id` is nullable | It is `NOT NULL` in the migration |

The first is stale — the code moved on. The last two are ADRs describing an implementation that
was never fully built; since ADRs are immutable, they need superseding entries rather than edits.

---

## Things that look wrong but are deliberate

**Do not "fix" these.** Each has a reason recorded somewhere.

| What you'll see | Why it's correct |
|---|---|
| `if ($x->tenant_id !== auth()->user()->tenant_id) return 403;` right after `$this->authorize(...)` | Deliberate defence in depth. `ExpenseController` calls it "belt-and-suspenders". |
| `orders.total` stored despite older "never store the total" comments | ADR-004 supersedes that rule. |
| Ledger entries edited in place by `adjustPayment` / `adjustOrderCharge` | ADR-006, the two sanctioned exceptions. |
| Credit `Payment` rows hard-deleted on order cancel | They're bookkeeping artifacts; their ledger entries remain. |
| Two ledger schemas (`type`-based and `direction`-based) in one table | Generational; unification is a planned migration, not a drive-by. |
| Arabic validation messages | The users are Arabic-speaking merchants. Do not translate. |
| `manual_total`, price overrides, backdated `order_date` | Merchant escape hatches. Flexibility is the feature. Do not add validation that blocks them. |
| `manual_total` not surviving an item edit | Confirmed and tested (`test_manual_total_does_not_survive_a_later_item_edit`). |
| Two different groupings (`$aggregated` and `$mergedItems`) over the same lines | Different keys for different jobs — see [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md) §6. |
| `lockForUpdate()` on reads that look like plain sums in `PaymentService` | Required — a plain read would reuse the pinned REPEATABLE READ snapshot. The comments explain it. |
| Microsecond timestamps on three tables | Feed ordering depends on them. |
| `wasRecentlyCreated` early-return in `OrderObserver::updated` | Filters the phantom create-time write. Tested. |

---

## Suggested order of work

Not a mandate — my reading of impact vs effort. Everything here is a small diff.

**Do first (correctness, all small):**
1. #1 — `adjustItem` unit conversion. Add a feature test for a secondary-unit item edit first; the
   test is worth more than the fix.
2. #2 — wrap `adjustOrderCharge` in `DB::transaction`. One line.
3. #11 — extract the balance type lists to constants. One refactor, removes a whole bug class.

**Do soon (cheap wins):**
4. #7 — eager-load `inventories.warehouse`. One line, and speed is the stated #1 requirement.
5. #3 — add `withTrashed()` to PO invoice numbering. One word.
6. #25 — fix the two stale lines in the AI guide. They actively mislead every future AI session,
   which is a compounding cost.

**Do when you're next in the area:**
7. #6 — route opening stock through `InventoryService`.
8. #9, #10 — paginate the unbounded reads.
9. #8 — decide what `unpaid_amount` means and make both places agree.
10. #12 — add locking to `SupplierPaymentService`.

**Discuss before doing** (these need a decision, not a patch):
- Product and warehouse deletion cascading away history ([DOMAIN_RULES.md](DOMAIN_RULES.md) §7) —
  needs a soft-delete/archive decision and a migration.
- #13 — extracting `ReportController` into a service. Real improvement, real churn, no behaviour
  change. Only worth it if reports are about to grow.
- #5 — the null-warehouse branch. Fix it, or decide ADR-007's null case is dead and make
  `warehouse_id` NOT NULL on `order_items` too. Don't leave it half-supported.

---

**Related documents**: [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md),
[DOMAIN_RULES.md](DOMAIN_RULES.md) §7 for deletion risks,
[ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) for the reasoning behind the deliberate items.
**Future improvements**: re-audit after each finding is addressed; append new findings with the same
severity scale.
**Open questions**: what should `unpaid_amount` mean (#8)? Should ADR-005 and ADR-007 be superseded
or should the schema be brought in line with them (#25)?
**Last review checklist**: [ ] line numbers still accurate, [ ] fixed items removed, [ ] "deliberate"
table matches the AI guide's divergence table. Last reviewed: 2026-08-30.
