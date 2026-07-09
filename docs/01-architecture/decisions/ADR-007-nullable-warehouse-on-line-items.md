# ADR-007: `warehouse_id` is nullable on order/PO line items

**Status**: Accepted **Date**: recorded 2026-07-08 (decision predates record)

## Context
Not every sale moves tracked stock: services, items sold before inventory was set up, ad-hoc goods. Forcing a warehouse on every line would block real sales — unacceptable for a POS.

## Decision
`order_items.warehouse_id` and `purchase_order_items.warehouse_id` are nullable. **Null warehouse = no stock check, no stock movement, no inventory transaction.** With a warehouse: full check → deduct/receive → `inventory_transactions` log. The branch is explicit in `OrderService::createOrder` and `PurchaseOrderService::createPurchaseOrder` (`if ($v['warehouseId']) { ... }`).

## Alternatives rejected
- **Mandatory warehouse + a fake "no warehouse" warehouse**: pollutes inventory reports with a pseudo-location and still needs special-casing everywhere.
- **Separate "service items" concept**: more modeling for the same behavior; nullable FK is the honest representation.

## Consequences
- Inventory reports understate reality if users skip warehouses out of laziness rather than intent — a training/UX concern, not a schema one.
- Every new stock-touching feature must handle the null branch explicitly. Forgetting it throws (`firstOrFail` in `InventoryService`) rather than corrupting data — acceptable failure mode.
- Related lock: warehouse deletion is blocked while it holds stock > 0.

---
**Related**: [Inventory & Warehouses](../../06-domain/inventory-and-warehouses.md). **Open questions**: none. **Last reviewed**: 2026-07-08.
