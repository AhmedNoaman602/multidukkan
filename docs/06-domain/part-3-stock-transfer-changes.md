# Part 3 — Stock Transfer: Old vs New

A study guide for everything Part 3 added: what the system did before, what changed, and why. "Before" means the last commits before Part 3 — backend `1a825a3`, frontend `1cf89e6` — verified against git. For the domain reference, see [stock-transfers.md](stock-transfers.md); for the one API-contract change in detail, see [stock-transfers-api-contract.md](stock-transfers-api-contract.md).

## 1. Part 3 goal

Part 3 adds a way to **move stock between two locations of the same store**, as one safe, recorded operation.

**Example — Storage → Shelf.** A shop sells from its shelf and keeps extra boxes in a back room ("Main" storage). The shelf runs low, so the manager moves 1 box (12 pieces) from Main to the shelf:

| | Before | After |
|---|---|---|
| Main (storage) | 100 | 88 |
| Shelf | 20 | 32 |
| **Store total** | **120** | **120** |

**Why a transfer is not a sale.** A sale takes stock *out of the business* and creates money owed (a ledger charge). A transfer only changes *where* stock sits: the store total doesn't change, nobody owes anything, so it writes **zero ledger entries** — an inventory-only event (locked decision in `CLAUDE.md`).

## 2. Before Part 3

**How stock was stored.** `inventory` (singular table) holds one row per (`warehouse_id`, `product_id`) — enforced by a unique index — with `quantity` always in **base units**. A "warehouse" is any stock location; since Part 2 each has `type = shelf | storage`, and every store has exactly one shelf.

**How inventory transactions worked.** `inventory_transactions` is the append-only history: `type`, `quantity`, polymorphic `reference_type`/`reference_id` (e.g. `App\Models\Order`), `user_id`, `notes`, `batch_id`. All changes go through `App\Services\InventoryService`:

- `deductStock` / `restoreStock` — sales, order cancels, PO receipts/cancels
- `adjustStock` — manual add/remove (`POST /inventory/{inventory}/adjust`)
- `ensureStockRow` — create a 0-quantity row for a new (location, product) pair
- `setStock` — absolute quantity, via `adjustStock`

**Was there a transfer concept?** Only on paper:

- `InventoryTransaction::TYPE_TRANSFER_IN` / `TYPE_TRANSFER_OUT` constants **already existed**, but nothing in the code wrote them.
- `CLAUDE.md` already had "Stock Transfer Rules" (statuses, approval, zero ledger), and `inventory-and-warehouses.md` had a section titled *"Phase 3 preview — Stock Transfers (not built)"*.
- No `stock_transfers` table, model, service, route or controller.

**Other things that already existed and Part 3 reuses:**

| Existing piece | From | Used by Part 3 for |
|---|---|---|
| `batch_id` on `inventory_transactions` + feed grouping by batch | July 2026 | Tying the OUT and IN rows into one event |
| `ensureStockRow()` | earlier | Creating a missing destination row |
| Unique (`warehouse_id`, `product_id`) on `inventory` | Phase 2 | Guarantees one row per pair to lock |
| `Product::factorFor()` / `unitNameFor()` | Part 1 | Resolving boxes → pieces |
| `Warehouse::TYPE_SHELF`, `Store::shelf()`, `WarehouseInStore` rule | Part 2 | Shelf badges, default destination, same-store validation |
| `lockForUpdate()` | `LedgerService`, `PaymentService` | The pattern — but **never on inventory rows** before Part 3 |

**What was missing.** The only way to move stock was two separate manual adjustments: "remove 12 from Main" and "add 12 to Shelf". That's two unrelated `ADJUSTMENT_*` rows, two requests (the second can fail after the first succeeded), no link between them, no record of the unit used, and the activity feed shows a loss and a gain instead of a move.

**Frontend before.** Inventory page with Add/Remove (adjust) buttons for non-staff. The activity feed already had labels and colors for `TRANSFER_IN` / `TRANSFER_OUT` (in `enums.js` and `lib/auditLog.js`) — unused. No transfers page, menu item, modal or `canTransferStock` permission.

## 3. After Part 3 — the complete flow

| Step | Where | Responsibility |
|---|---|---|
| User opens transfer UI | `pages/Inventory.jsx` (⇄ Transfer on a row) or `pages/StockTransfers.jsx` ("New transfer") | Opens `StockTransferModal`, optionally with source + product prefilled |
| Selects source | `components/StockTransferModal.jsx` | Lists locations from `GET /warehouses`, shelf first |
| Selects destination | same | Only locations **in the source's store**, excluding the source; storage source → shelf preselected |
| Product / unit / quantity | same | `ProductSearchInput`, unit toggle, quantity, notes; shows a *preview* of the base quantity |
| Sends request | same, `handleSubmit` | `POST /api/stock-transfers` |
| Validation | `App\Http\Requests\StoreStockTransferRequest::rules()` | Shape, tenant ownership, same store, distinct products, prohibited server fields |
| Authorization (role) | `StoreStockTransferRequest::authorize()` → `StockTransferPolicy::create` | Admin or manager; staff get 403 before validation |
| Controller | `Api\V1\StockTransferController::store` | Loads source warehouse, checks `StockTransferPolicy::createFrom` (manager must own the source store), calls the service, returns 201 |
| Service | `App\Services\StockTransferService::transfer` | Re-checks tenant/store, creates header + lines, calls `transferStock` per line |
| DB transaction | `DB::transaction` in `transfer()` | All-or-nothing |
| Row locking | `InventoryService::transferStock` | `lockForUpdate()` on both rows, in id order |
| Quantity changes | same | `decrement` source, `increment` destination |
| `TRANSFER_OUT` / `TRANSFER_IN` | same | Two `inventory_transactions` rows, same reference and `batch_id` |
| Audit event | `AuditLogController::index` (read time) | Shows the pair as one `TRANSFER` event |
| Response | `App\Http\Resources\StockTransferResource` | Transfer with from/to, creator, lines (entered + base qty) |

Routes added to `routes/api.php` (inside the `auth:sanctum` group): `GET /stock-transfers`, `POST /stock-transfers`, `GET /stock-transfers/{stockTransfer}`.

## 4. Database changes

One migration: `database/migrations/2026_10_01_100000_create_stock_transfers_table.php`.

**`stock_transfers`** — *old: didn't exist.* One row per transfer (the "header").

| Column | Stores | Why |
|---|---|---|
| `tenant_id`, `store_id` | Owner tenant and store | Tenant scoping; store-level listing |
| `from_warehouse_id`, `to_warehouse_id` | The two locations (FK `warehouses`, `RESTRICT`) | A location with transfer history can't vanish |
| `type` | `manual` (Part 3) / `replenishment` (Part 4) | Who caused it |
| `status` | `COMPLETED` in Part 3 | Room for the later approval flow |
| `order_id` | nullable FK `orders` | For Part 4 replenishments; always null now |
| `created_by` | nullable FK `users`, `nullOnDelete` | Who did it |
| `notes` | up to 500 chars | Optional reason |
| `batch_id` | uuid | Copied onto both history rows |
| `completed_at`, timestamps | When it happened | |

Index `(tenant_id, store_id, created_at)` — matches the list query.

**`stock_transfer_items`** — *old: didn't exist.* One row per product moved: `stock_transfer_id` (cascade), `product_id` (`RESTRICT`), `quantity` **as entered** (e.g. 1), `unit_type`, `conversion_factor` (12), `unit_name` ("box"). Same snapshot shape as `order_items` (Part 1). Why: history only stores base units; this keeps what the user actually chose, frozen even if the product's box size changes later.

**`inventory_transactions`** — *old: no index on the reference columns. New:* index on `(reference_type, reference_id)`. Why: finding all stock rows caused by one transfer (or order) is now an index lookup.

**Relationships:**

```
stores 1─* stock_transfers *─1 warehouses (from)
                           *─1 warehouses (to)
                           *─0..1 orders        (Part 4)
                           *─0..1 users (created_by)
stock_transfers 1─* stock_transfer_items *─1 products
stock_transfers 1─* inventory_transactions  (reference_type = StockTransfer, same batch_id)
```

Models: `App\Models\StockTransfer` (constants `TYPE_MANUAL`, `TYPE_REPLENISHMENT`, `STATUS_*`; relations `items`, `store`, `fromWarehouse`, `toWarehouse`, `order`, `creator`) and `App\Models\StockTransferItem` (`baseQuantity()`, `stockTransfer`, `product`). Both use `ScopedToTenant`.

## 5. How the transfer actually works

The work is split: **`StockTransferService::transfer()`** owns the business operation (the record); **`InventoryService::transferStock()`** owns the stock movement (locks + quantities + history). Rule #4 of the AI guide — only `InventoryService` touches `inventory.quantity` — is why the split exists.

1. **What enters.** Validated data (`from_warehouse_id`, `to_warehouse_id`, `notes`, `items[]` with `product_id`, `quantity`, `unit_type`) and the acting `User`.
2. **What it re-checks.** The request already validated everything; the service re-verifies anyway (AI guide rule #5 — never trust only the global scope).
3. **Finding source and destination.** `Warehouse::where('tenant_id', ...)->findOrFail(...)` for both. If they're the same row or in different stores → `ValidationException` (422). Products are loaded once with `whereIn` + `keyBy` (no query per line).
4. **Header and lines.** Creates `stock_transfers` (`manual`, `COMPLETED`, new uuid `batch_id`, `completed_at = now()`), then one `stock_transfer_items` row per line using `factorFor($unitType)` and `unitNameFor($unitType)`.
5. **Destination row.** For each line, `transferStock` first calls `ensureStockRow($product, $to, $tenant, 0)` — creates the row with quantity 0 and threshold 0 if missing (threshold 0 so a new row doesn't instantly show "low stock").
6. **Locking.**
   ```php
   $ids  = Inventory::where('product_id', $productId)->whereIn('warehouse_id', [$from, $to])->pluck('id');
   $rows = Inventory::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('warehouse_id');
   ```
   `lockForUpdate()` makes other transactions wait before touching these rows until this one commits.
7. **Deterministic.** Rows are locked by ascending **id**, never "source first". Every transfer locking the same two rows locks them in the same order — see §6.
8. **Insufficient stock.** After locking (so the number can't change underneath): if the source row is missing or `quantity < base` → 422 with `messages.insufficient_stock` (product, location, available).
9. **Decrease source** — `$source->decrement('quantity', $base)`.
10. **Increase destination** — `$destination->increment('quantity', $base)`.
11. **`TRANSFER_OUT`** — at the source warehouse, `quantity = base`, `reference_type = StockTransfer::class`, `reference_id = transfer id`, `batch_id = transfer batch`, `user_id`.
12. **`TRANSFER_IN`** — same fields, at the destination warehouse.
13. **Transaction protection.** All of the above runs inside `DB::transaction` in `transfer()` (`transferStock`'s own `DB::transaction` becomes a nested savepoint). Locks are held until commit.
14. **Failure halfway.** Any exception — e.g. line 2 is short after line 1 already moved — propagates out of the closure and MySQL **rolls back everything**: header, lines, every quantity change, every history row. The user gets the 422; the database looks as if nothing happened.

**Worked example** — Storage(Main) = 100, Shelf = 20, transfer 1 box (factor 12):

`stock_transfers`

| id | from | to | type | status | order_id | batch_id |
|---|---|---|---|---|---|---|
| 7 | Main | Shelf | manual | COMPLETED | null | `b1…` |

`stock_transfer_items`

| stock_transfer_id | product | quantity | unit_type | conversion_factor | unit_name |
|---|---|---|---|---|---|
| 7 | Screws | 1 | secondary | 12 | box |

`inventory_transactions`

| warehouse | type | quantity | reference | batch_id |
|---|---|---|---|---|
| Main | TRANSFER_OUT | 12 | StockTransfer #7 | `b1…` |
| Shelf | TRANSFER_IN | 12 | StockTransfer #7 | `b1…` |

`inventory`: Main **88**, Shelf **32**. `ledger_entries`: **no rows**.

## 6. Multi-product transfers and lock ordering

**The deadlock, simply.** Two people act at the same moment:

- Transfer A: Main → Shelf (rows: Main id 5, Shelf id 9)
- Transfer B: Shelf → Main

If each locked *its source first*:

```
A locks Main(5) ……… then waits for Shelf(9)
B locks Shelf(9) …… then waits for Main(5)
```

Each holds what the other needs; neither can finish. MySQL detects it and kills one transaction — a user sees a random failure.

**With id order**, both lock Main(5) first. Whoever gets it also gets Shelf(9); the other simply waits, then runs. No cycle is possible because everyone climbs the same ladder in the same direction.

**Multiple products.** One transfer with products P3 and P8 locks P3's two rows, then P8's two rows — all held until commit. If another transfer had the lines in the opposite order (P8, then P3), the same cycle could appear one level up. So `StockTransferService` moves lines **sorted by `product_id`**:

```php
foreach (collect($items)->sortBy('product_id') as $item) { ... transferStock(...) }
```

Lines are still *saved* in the order the user entered them; only the *movement* is sorted.

## 7. Audit / event behavior

Two different things:

- **Inventory transactions** — the accounting truth for stock. Each location's history must explain its own quantity, so a transfer needs **two** rows: Main lost 12 (`TRANSFER_OUT`), Shelf gained 12 (`TRANSFER_IN`).
- **User-facing event** — what a person in the Activity feed wants: "12 moved Main → Shelf", **once**.

The feed (`AuditLogController::index`) already grouped inventory rows by `batch_id`. Because both rows share the transfer's `batch_id`, they already became one group — but the group's quantity was `SUM(quantity)` = 24 (double). Part 3 changed:

- the sum to `SUM(CASE WHEN type = "TRANSFER_IN" THEN 0 ELSE quantity END)` → 12;
- the row type to `TRANSFER` when `reference_type` is `StockTransfer` (instead of whichever of OUT/IN sorted last);
- `entity_name` to `"Main → Shelf"`, `auditable_type` to `StockTransfer`;
- `GET /audit-log/inventory-batches/{batchId}` now also returns each row's `type`.

Frontend: `TRANSFER` label/color (`enums.auditType`, `lib/auditLog.js`), `StockTransfer` entity label; `AuditLogDrawer.jsx` shows the quantity **without a + sign or green/red** (nothing was gained or lost), and in multi-product batch details shows `TRANSFER_OUT` rows as negative.

## 8. Permissions

| Role | Create | View (API) | Frontend |
|---|---|---|---|
| `tenant_admin` | ✅ from any location in the tenant | All stores; may filter by `store_id` | Menu item, page, Transfer button, all locations (labelled with store name when there are several stores) |
| `store_manager` | ✅ only if the **source** is in their store | Own store only | Same UI; `GET /warehouses` already returns only their store's locations |
| `store_staff` | ❌ 403 | Own store only (`GET` is allowed) | No menu item; `/stock-transfers` redirects to dashboard; no Transfer button |

**Backend enforcement, in order:**

1. `StoreStockTransferRequest::authorize()` → `StockTransferPolicy::create` — role check. Runs **before validation**, so staff get 403 even with a bad payload.
2. Controller → `StockTransferPolicy::createFrom($user, $fromWarehouse)` — a manager of store B trying to move store A's stock gets 403.
3. `StockTransferPolicy::view` — admin, or same store. `index` filters by `store_id` for store users.

**Why hiding a button isn't security.** The frontend helper `canTransferStock()` (`lib/permissions.js`) only decides what to *show*. Anyone can send `POST /api/stock-transfers` with curl or Postman, ignoring the UI entirely. The policy and FormRequest are what actually refuse it — and the tests prove they do.

## 9. Frontend

**New files**

- `src/components/StockTransferModal.jsx` — the form.
- `src/pages/StockTransfers.jsx` — list (date, type, from → to with shelf badges, lines as "1 box (12 pcs)", status, creator, notes), filters (type, location, store for admins), pagination, "New transfer".
- `src/i18n/{en,ar}/stockTransfers.js` — strings, registered in both `i18n/*/index.js`.

**Changed**: `App.jsx` (route behind `RoleRoute allow={canTransferStock}`), `Sidebar.jsx` (Transfers item), `Inventory.jsx` (⇄ Transfer button, disabled at 0 stock), `lib/permissions.js` (`canTransferStock`), `lib/auditLog.js`, `AuditLogDrawer.jsx`, enums / navigation / inventory translations.

**What the user selects**: source, destination (same store only, shelf marked, can't equal source), product, unit (only if the product has a secondary unit with factor > 1), integer quantity, optional notes.

**Request sent:**

```json
{ "from_warehouse_id": 5, "to_warehouse_id": 9, "notes": null,
  "items": [{ "product_id": 3, "quantity": 1, "unit_type": "secondary" }] }
```

**Errors**: the modal shows the first validation error (or the `message`, e.g. insufficient stock) inside the modal and stays open; success shows a toast, closes, and refreshes the `inventory` and `stock-transfers` queries.

**Why the frontend doesn't convert or move stock.** It sends *what the user chose* (1 box), not 12. The "Preview: 12 pcs will move" line is labelled as a preview. The server converts with the product's current factor, checks real stock under a lock, and decides. If the frontend sent base units it could use a stale factor, or two tabs could both "see" enough stock — the server is the only place that knows the truth at the moment of the move.

**One product per transfer in the UI.** The API accepts up to 50 lines; the modal sends one. That's fine because the common case is "refill this product on the shelf", and adding multi-line UI later needs no backend change — the contract already supports it.

## 10. API contract

**Request fields** (`StoreStockTransferRequest`):

| Field | Rule |
|---|---|
| `from_warehouse_id` | required, integer, belongs to tenant |
| `to_warehouse_id` | required, integer, `different:from_warehouse_id`, belongs to tenant, `WarehouseInStore(source's store)` |
| `notes` | nullable, max 500 |
| `items` | required, 1–50 |
| `items.*.product_id` | required, `distinct`, belongs to tenant |
| `items.*.quantity` | required, integer 1–1,000,000 |
| `items.*.unit_type` | nullable, `base` / `secondary` |
| `type`, `status`, `order_id` | **`prohibited`** |

**Server-controlled fields.** `type` is always `manual`, `status` always `COMPLETED`, `order_id` always null for this endpoint — set inside `StockTransferService`. A client could otherwise try to fake a replenishment.

**`prohibited` and 422.** First version: those three fields had no rule, so `validated()` silently dropped them and the request returned 201. After review they became `prohibited`: sending one returns **422** (the standard "valid JSON, invalid input" response), and since validation runs before the controller, nothing is written. **`order_id: null` edge case**: `prohibited` treats null/empty as "not sent", so it passes — harmless, because the service never reads these keys. Full walkthrough: [stock-transfers-api-contract.md](stock-transfers-api-contract.md).

## 11. Tests

All in `tests/Feature/StockTransferTest.php` (12 tests, 114 assertions). Fixture: one store with a shelf (10 pcs) and a storage location (100 pcs); product with box = 12; admin, manager and staff users.

**Correct movement**
- *Manager moves 2 boxes storage → shelf*: 201; storage 76, shelf 34, total unchanged; line saved as 2 / secondary / 12 / box; exactly one `TRANSFER_OUT` and one `TRANSFER_IN` of 24, same reference, same `batch_id`, right user; `completed_at` set; **0 ledger entries**. Without it: wrong conversion, a missing history row, or an accidental ledger write would ship silently.
- *Destination row created when missing*: admin moves 4 pcs shelf → a new back room; the row appears with quantity 4 and threshold 0. Without it: transfers to a never-stocked location would 500.

**Rejected input moves nothing** — each asserts 422 *and* `assertNothingMoved()` (no header, no lines, no history, quantities 10 / 100):
- insufficient stock (9 boxes = 108 > 100);
- source = destination;
- destination in another store (plus: no stock row created there);
- warehouse from another tenant.

Without these: a partial write after an error, or a cross-store / cross-tenant leak.

**Authorization**
- Staff → 403, nothing moved.
- Manager of another store → 403, nothing moved.

Without these: the "who may move stock" rule could regress unnoticed — the UI hiding a button wouldn't catch it.

**API contract**
- Sending `type: replenishment`, `status: PENDING`, `order_id` → 422 on all three, nothing moved.
- Each field alone (even with the value the server would use) → 422.

Without these: a client could forge a replenishment once Part 4 gives that type meaning.

**Visibility**
- Manager and staff of store A see only A's transfer; admin sees both; `warehouse_id` filter works; manager of A gets 403 on B's transfer; staff can view their own store's transfer.

**Audit**
- One transfer of 24 shows as **one** `TRANSFER` row with quantity 24, entity `"Main → Shelf"`. Without it: the feed shows 48 — the double count this part fixed.

**Why check the DB after a 422?** The status code is what the server *said*; the tables are what it *did*. A 422 after stock already moved would make users retry and move stock twice.

**Suite totals**: before Part 3, 446 tests; after Part 3, **458 passed (1,642 assertions)**.

## 12. Old vs New summary

| Area | Before Part 3 | After Part 3 | Why |
|---|---|---|---|
| Transfer creation | None — two manual adjustments | `POST /stock-transfers` → `StockTransferService` | One operation, one record |
| Stock movement | `ADJUSTMENT_OUT` + `ADJUSTMENT_IN`, two requests, not linked | `transferStock`: both sides in one transaction | Can't half-happen |
| Ledger | n/a | Still zero entries | Moving stock isn't money |
| Audit | Constants & labels for TRANSFER_* existed, unused; batch sum would double a pair | One `TRANSFER` event, OUT side counted, "from → to" | Users see a move, once |
| Locking | `lockForUpdate` only in ledger/payment code | Both inventory rows locked in id order; lines in product order | No oversell, no deadlock |
| Permissions | Rules written in `CLAUDE.md` only | `StockTransferPolicy` (`create`, `createFrom`, `view`) | Enforced server-side |
| API validation | n/a | `StoreStockTransferRequest`; server fields `prohibited` | Explicit contract |
| Frontend | Add/Remove only; unused TRANSFER labels | Modal, Transfers page, Inventory action, menu, drawer | Usable without Postman |
| Tests | 446 | 458 (+12 in `StockTransferTest`) | Protect movement, rejection, permissions, contract, audit |

## 13. Complete Part 3 flow

```
User (manager)
  │  clicks ⇄ Transfer, picks Main → Shelf, 1 box
  ▼
Frontend  StockTransferModal  ── preview only, no conversion
  │  POST /api/stock-transfers {from, to, items:[{product, 1, secondary}]}
  ▼
Validation      StoreStockTransferRequest   (shape, tenant, same store, prohibited fields)
Authorization   authorize() → Policy::create ; Controller → Policy::createFrom
  ▼
Controller      StockTransferController::store
  ▼
Service         StockTransferService::transfer
  │  DB::transaction {
  │     header + lines (1 box, factor 12)
  │     InventoryService::transferStock
  │        ensure destination row
  │        lock rows by id
  │        check 100 ≥ 12
  │        Main 100 → 88, Shelf 20 → 32
  │        TRANSFER_OUT 12 + TRANSFER_IN 12 (same batch)
  │  }  commit (or roll back everything)
  ▼
Ledger          nothing written
Audit           read-time: feed groups the batch → one TRANSFER "Main → Shelf", 12
  ▼
Response        201 StockTransferResource → toast, inventory + list refresh
```

In words: the user only *describes* the move. The request layer checks the description is allowed and complete; the policy checks this user may move stock out of that location; the service records the transfer and asks `InventoryService` to move the stock. The move happens under locks inside one transaction, so either both sides change and both history rows exist, or nothing changed at all. No money is involved, so the ledger is untouched. The Activity feed later reads the two history rows and shows them as a single transfer.

## 14. What Part 3 deliberately does NOT do

- **Replenishment transfers (Part 4).** `type = replenishment` and `order_id` exist in the schema and model, but **nothing creates them yet**. In Part 4 a sale whose shelf is short will create one automatically.
- **Shelf-first sale fulfillment (Part 4).** Orders still use the warehouse the seller picks (limited to the order's store since Part 2).
- **Staff request → manager approval.** `PENDING` / `APPROVED` / `REJECTED` constants exist; only `COMPLETED` is used. Staff get 403.
- **Cross-store transfers.** Rejected by validation and re-checked in the service.
- **Reversing/cancelling a transfer.** No endpoint; a mistake is fixed with a transfer the other way.
- **UI gaps by choice**: one product per transfer in the modal; no product filter on the list page (the API supports both); `GET /stock-transfers/{id}` exists but the frontend doesn't use it yet.
- **Opening stock per location** — Part 5.

---
**Related documents**: [Stock Transfers](stock-transfers.md), [API contract change](stock-transfers-api-contract.md), [Inventory & Warehouses](inventory-and-warehouses.md), [ADR-010](../01-architecture/decisions/ADR-010-stock-locations-and-shelf-fulfillment.md). **Last reviewed**: 2026-10-01.
