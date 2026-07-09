# ADR-008: Weighted-average cost stored on `products.cost_price`

**Status**: Accepted **Date**: ~2026-06-07 (migration `add_cost_price_to_products_table`), recorded 2026-07-08

## Context
Profit reporting needs a cost basis per product. Purchase prices vary between POs and suppliers. Small merchants think in terms of "what does this item cost me on average", not lot tracking.

## Decision
Each purchase order line recomputes the product's weighted-average cost:

```
new_avg = (current_stock × current_cost + purchased_qty × line_price) / (current_stock + purchased_qty)
```

Implemented in `PurchaseOrderService::createPurchaseOrder` with a **running per-product map** (`$runningStock` / `$runningCost`) so multiple lines of the same product within one PO chain correctly (line 2 builds on line 1's result). All quantities/prices are normalized to **base units** before the math (see [costing rules](../../07-business-rules/costing-and-inventory-rules.md)). Per-supplier `last_purchase_price` is tracked separately on the `supplier_products` pivot.

## Alternatives rejected
- **FIFO/LIFO lot costing**: requires lot/batch tracking that the business doesn't do physically; large modeling cost for precision the user can't verify against reality.
- **Last purchase price as cost**: whipsaws profit numbers on every price change; the pivot keeps this per supplier for negotiation insight instead.

## Consequences
- `cost_price` is mutated by PO creation — profit reports are as-of-now, not historical. If historical margin per order is ever needed, snapshot cost on `order_items` at sale time (future work, cheap to add).
- **Cancelling a PO does not rewind `cost_price`** — reversing a weighted average is ill-defined. Known, accepted imprecision.
- Stale `BUG:` comment in `PurchaseOrderService` describes the pre-fix behavior; the running-map fix below it is implemented. The comment should be cleaned up.

---
**Related**: [Costing & Inventory Rules](../../07-business-rules/costing-and-inventory-rules.md), [Suppliers & POs](../../06-domain/suppliers-and-purchase-orders.md). **Open questions**: snapshot `cost_price` onto `order_items` for true historical profit? **Last reviewed**: 2026-07-08.
