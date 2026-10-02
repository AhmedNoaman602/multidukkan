# Inventory, Warehouses & Inventory Transactions

Physical stock truth: **`inventory.quantity` is the current state; `inventory_transactions` is the append-only history of how it got there.** Every mutation writes both, always through `InventoryService`.

## Schema

- `warehouses`: tenant-scoped locations. Soft-deletable; **deletion blocked while stock > 0** (locked decision).
- `inventory` (singular table name — `$table = 'inventory'` on the model): one row per (`warehouse_id`, `product_id`), `quantity` **always in base units**.
- `inventory_transactions`: append-only log — `type`, `quantity`, `reference_id`/`reference_type` (polymorphic cause: Order, PurchaseOrder, …). No soft deletes.

## Transaction types ↔ service methods

| `InventoryService` method | Transaction type | Triggered by |
|---|---|---|
| `checkStock` | — (read/guard) | No longer called by orders (Part 4); kept until the Part 6 cleanup |
| `deductStock` | `TYPE_SALE` | Sales from the shelf (via `StockFulfillmentService`); PO cancellation. Locks the row and returns 422 instead of going below zero |
| `lockRows` | — | Locks a set of rows in id order before fulfillment decides anything |
| `storeAvailability` | — (read) | `GET /inventory/availability`: shelf vs storage per product for one store, one grouped query; display only |
| `restoreStock` | `TYPE_RETURN` | Order cancel / qty reduction; **also PO receiving** (quirk below) |
| `adjustStock` | `TYPE_ADJUSTMENT_IN` / `_OUT` | Manual `POST /inventory/{i}/adjust`; handles secondary-unit conversion itself; blocks negative result |
| `transferStock` | `TYPE_TRANSFER_OUT` + `TYPE_TRANSFER_IN` | Stock transfers between two locations of one store — see [stock-transfers.md](stock-transfers.md); locks both rows, blocks negative result |

**Semantic quirk (accepted for now)**: purchase orders reuse `restoreStock`/`deductStock`, so PO receipts log as `RETURN` and PO cancellations log as `SALE`. The `reference_type = PurchaseOrder::class` disambiguates, but any report that reads transaction types as business meaning must join the reference. A `TYPE_PURCHASE`/`TYPE_PURCHASE_REVERSAL` pair would be more honest — candidate cleanup.

## Invariants

1. **No mutation without a transaction row.** `Inventory::increment/decrement` outside `InventoryService` is forbidden. (Known violation: `Product::booted()` hard-deletes inventory rows on product delete without logging — flagged in [products-and-units.md](products-and-units.md).)
2. **Base units only** in `inventory.quantity` and transaction quantities. Conversion happens before the service call (orders/POs) or inside `adjustStock`.
3. Stock can be zero but never negative. Sales lock the shelf and storage rows (`lockRows`, id order) before checking, so two simultaneous sales of the last unit queue instead of overselling.
4. Every sale line has a location (`order_items.warehouse_id` NOT NULL, [ADR-010](../01-architecture/decisions/ADR-010-stock-locations-and-shelf-fulfillment.md)).

## Shelf-first fulfillment (Part 4)

`StockFulfillmentService::fulfill(order, location, baseNeeds, user, batchId)`, called by create order, add item and quantity increases, inside the order's transaction:

1. Eligible sources: the store's `storage` locations — only when the location is the shelf.
2. Ensure a shelf row per product, then `lockRows` on the shelf + sources.
3. Per product: shortfall = need − shelf. Cover it from sources sorted by quantity (highest first), then warehouse id (lowest first), several if needed. If storage can't cover it → 422 `insufficient_store_stock` (shelf and storage totals) before anything is written.
4. One `replenishment` transfer per source used (`StockTransferService::replenish`, linked to the order, `COMPLETED`, no approval).
5. `deductStock` each product's need from the shelf (`SALE`, the order's batch).

## Stock Transfers

Manual same-store transfers (Part 3) and automatic shelf replenishment during sales (Part 4) are built; the staff request/approval flow is not. See [stock-transfers.md](stock-transfers.md).

---
**Related documents**: [Costing & Inventory Rules](../07-business-rules/costing-and-inventory-rules.md), [ADR-007](../01-architecture/decisions/ADR-007-nullable-warehouse-on-line-items.md), [Products & Units](products-and-units.md).
**Future improvements**: dedicated PURCHASE transaction types; `lockForUpdate` on stock checks; low-stock alerts (pairs with Notifications roadmap).
**Open questions**: should manual adjustments require a reason field for audit?
**Last review checklist**: [ ] type table matches `InventoryTransaction` constants, [ ] invariant violations list current. Last reviewed: 2026-07-08.
