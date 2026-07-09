# Costing & Inventory Rules

The rules that keep stock counts and cost numbers honest across units, warehouses, and purchase orders.

## Rule 1 — Base units are the only truth

`inventory.quantity`, `inventory_transactions.quantity`, and `products.cost_price` are **always in base units**. Secondary units (`unit_type = 'secondary'`) exist only at the input/display edge and are converted immediately on entry:

```
stock_qty  = input_qty  × conversion_factor
unit_price = input_price(or tier price) × conversion_factor   // price per secondary unit
```

Conversion currently lives in three places (`OrderService`, `PurchaseOrderService`, `InventoryService::adjustStock`). **Any change to conversion semantics must hit all three** — a partial change silently corrupts stock. (Standing refactor candidate: extract a `UnitConverter`.)

Edge case: a product with a secondary unit but `conversion_factor` null/0 falls back to base behavior everywhere (`&& $product->conversion_factor` guards). Don't remove those guards.

## Rule 2 — Every stock mutation writes a transaction row

State (`inventory.quantity`) and history (`inventory_transactions`) move together, via `InventoryService` only. Mapping and the PO `RETURN`/`SALE` type quirk: see [inventory-and-warehouses.md](../06-domain/inventory-and-warehouses.md).

## Rule 3 — Stock movements per business event

| Event | Movement | Notes |
|---|---|---|
| Order created | deduct per warehoused line | after aggregate `checkStock` across duplicate lines (two lines of the same product+warehouse are checked as their sum — prevents passing two checks that jointly oversell) |
| Order item qty increased | check + deduct the delta | |
| Order item qty decreased | restore the delta | |
| Order cancelled | restore every warehoused line | secondary converted to base via the item's product |
| PO created | receive (increment) per warehoused line | |
| PO cancelled | deduct what was received | can drive stock to firstOrFail-guarded rows but NOT below zero checks — deduct has no floor check; cancelling a PO after the goods sold can go negative. Known edge |
| Manual adjust | in/out with floor check on out | requires direction + optional secondary unit |

## Rule 4 — Weighted-average cost (full spec)

On **every PO line**, in input order:

```
current_stock = runningStock[product] ?? pre-PO total stock across ALL warehouses
current_cost  = runningCost[product]  ?? product.cost_price ?? line price
new_avg       = (current_stock × current_cost + line_qty × line_price) / (current_stock + line_qty)
              (fallback: line_price when denominator ≤ 0)
product.cost_price = round(new_avg, 2); update running maps
```

Consequences to remember:
- Cost is **tenant-global**, not per-warehouse (stock is summed across warehouses).
- Lines *without* a warehouse still move the average (they represent purchased goods even if untracked).
- PO cancellation does **not** rewind the average ([ADR-008](../01-architecture/decisions/ADR-008-weighted-average-costing.md)).
- Sales never change cost. Only purchases do.
- `supplier_products.last_purchase_price` records the per-supplier raw price separately — negotiation data, not costing input.

## Rule 5 — Profit

`unit_profit = sale unit_price − product.cost_price` (as-of-now cost). Reports computing margin must state this caveat until cost is snapshotted on order items at sale time (planned improvement — would make historical profit exact).

## Concurrency posture (accepted risks at current volume)

- `checkStock` → `deductStock` has no row lock: simultaneous last-unit sales can oversell. Fix-when-needed: `lockForUpdate()` on the inventory row inside the order transaction.
- Invoice-number generation has a read-then-insert race under concurrent order creation (plus the 999/year parsing defect). Fix together when addressed.

---
**Related documents**: [ADR-008](../01-architecture/decisions/ADR-008-weighted-average-costing.md), [Inventory & Warehouses](../06-domain/inventory-and-warehouses.md), [Suppliers & POs](../06-domain/suppliers-and-purchase-orders.md).
**Future improvements**: `UnitConverter` extraction; cost snapshot on order items; floor check on PO-cancel deduction.
**Open questions**: per-warehouse costing — no requirement yet, revisit only if warehouses get different acquisition costs systematically.
**Last review checklist**: [ ] conversion sites still exactly three, [ ] cost spec matches `PurchaseOrderService`. Last reviewed: 2026-07-08.
