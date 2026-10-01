# ADR-010: Stock locations, the store shelf, and shelf-first fulfillment

**Status**: Accepted — supersedes [ADR-007](ADR-007-nullable-warehouse-on-line-items.md) **Date**: 2026-10-01

## Context
Inventory already lives per product per warehouse, and every warehouse belongs to a store. What was missing was the physical difference between the place a shop sells from (the shelf) and the places it keeps stock (back rooms, warehouses). The order screen asked the seller to pick a warehouse for every line, nothing stopped a line from drawing on another store's warehouse, and ADR-007 still described order lines without a warehouse — a path the API had already closed (`items.*.warehouse_id` was required).

Stock stays canonical in the product's base unit (settled separately); this ADR is only about *where* that stock is.

## Decision
- **The store is the organisational boundary; warehouses are stock locations.** No separate `locations` table — inventory, stock history and deletion guards already key on `warehouse_id`.
- **`warehouses.type` is `shelf` or `storage`.** Every store has exactly one shelf, created in the same transaction as the store (`StoreService::createStore`). A generated column `shelf_store_id` with a unique index makes "at most one shelf per store" a database guarantee; creation with the store and the deletion guard make it "exactly one". Every user-created warehouse is `storage`; the type can't be changed.
- **The shelf can't be deleted on its own.** It goes with its store, and only while it has never held or moved stock.
- **Store stock is derived** by summing the store's locations. There is no store-level stock row.
- **`order_items.warehouse_id` is NOT NULL** (`ON DELETE RESTRICT`). It is the historical fact of where that line's stock left from — not a selling-location setting — and every inventory sale line has one. An order line may only use a location of the order's own store.
- **Sales are fulfilled from the shelf.** If the shelf is short, the backend replenishes the exact shortfall from the same store's storage locations (highest stock first, lowest id on ties, several if needed) and then deducts from the shelf, atomically.
- **Automatic replenishment is a system operation, not a manual transfer.** It is authorised by the sale itself, recorded as a `COMPLETED` replenishment transfer linked to the order, and needs no approval. The request/approve rule for transfers applies to manual, user-initiated transfers only.

## Alternatives rejected
- **A pointer on the store (`stores.selling_warehouse_id`)**: the shelf is the selling location by domain rule, not configuration; a pointer adds a circular foreign key and invites re-pointing that would make historical fulfillment locations ambiguous.
- **A separate `locations` system**: same behaviour, large churn across inventory, history and tests.
- **Keeping nullable `warehouse_id` for non-stock lines**: MultiDukkan's sales are inventory-based; a speculative null path is what ADR-007 left behind and the API had already removed.

## Consequences
- Implemented so far: shelf type and uniqueness, shelf created with every store, shelf deletion guard, NOT NULL `order_items.warehouse_id`, same-store validation for order lines. Shelf-first fulfillment, automatic replenishment and transfers land in later steps; until then the seller still picks the location, limited to the order's store.
- Every reversal (cancel, quantity edit) restores to the line's recorded `warehouse_id`, never to "today's shelf".
- Stores created before this change need `migrate:fresh` (pre-launch).

---
**Related**: [Inventory & Warehouses](../../06-domain/inventory-and-warehouses.md), [ADR-007](ADR-007-nullable-warehouse-on-line-items.md). **Open questions**: none. **Last reviewed**: 2026-10-01.
