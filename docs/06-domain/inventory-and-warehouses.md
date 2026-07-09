# Inventory, Warehouses & Inventory Transactions

Physical stock truth: **`inventory.quantity` is the current state; `inventory_transactions` is the append-only history of how it got there.** Every mutation writes both, always through `InventoryService`.

## Schema

- `warehouses`: tenant-scoped locations. Soft-deletable; **deletion blocked while stock > 0** (locked decision).
- `inventory` (singular table name — `$table = 'inventory'` on the model): one row per (`warehouse_id`, `product_id`), `quantity` **always in base units**.
- `inventory_transactions`: append-only log — `type`, `quantity`, `reference_id`/`reference_type` (polymorphic cause: Order, PurchaseOrder, …). No soft deletes.

## Transaction types ↔ service methods

| `InventoryService` method | Transaction type | Triggered by |
|---|---|---|
| `checkStock` | — (read/guard) | Order create/adjust — throws Arabic-message `ValidationException` when insufficient |
| `deductStock` | `TYPE_SALE` | Order lines with a warehouse; **also PO cancellation** (see quirk below) |
| `restoreStock` | `TYPE_RETURN` | Order cancel / qty reduction; **also PO receiving** (quirk below) |
| `adjustStock` | `TYPE_ADJUSTMENT_IN` / `_OUT` | Manual `POST /inventory/{i}/adjust`; handles secondary-unit conversion itself; blocks negative result |

**Semantic quirk (accepted for now)**: purchase orders reuse `restoreStock`/`deductStock`, so PO receipts log as `RETURN` and PO cancellations log as `SALE`. The `reference_type = PurchaseOrder::class` disambiguates, but any report that reads transaction types as business meaning must join the reference. A `TYPE_PURCHASE`/`TYPE_PURCHASE_REVERSAL` pair would be more honest — candidate cleanup.

## Invariants

1. **No mutation without a transaction row.** `Inventory::increment/decrement` outside `InventoryService` is forbidden. (Known violation: `Product::booted()` hard-deletes inventory rows on product delete without logging — flagged in [products-and-units.md](products-and-units.md).)
2. **Base units only** in `inventory.quantity` and transaction quantities. Conversion happens before the service call (orders/POs) or inside `adjustStock`.
3. Stock can be zero but not negative via `adjustStock`/`checkStock` paths. **Race window**: `checkStock` then `deductStock` without row locking — two simultaneous sales of the last unit can oversell. Accepted at current volume; fix is `lockForUpdate()` inside the order transaction when it matters.
4. Null-warehouse lines skip this entire subsystem ([ADR-007](../01-architecture/decisions/ADR-007-nullable-warehouse-on-line-items.md)).

## Phase 3 preview — Stock Transfers (not built)

Locked rules from CLAUDE.md: statuses `PENDING/APPROVED/REJECTED/COMPLETED`; source store manager or tenant_admin approves; staff request only; approval atomically deducts source + adds destination logging `TRANSFER_OUT` + `TRANSFER_IN`; rejection moves nothing; **zero ledger entries** (inventory-only event). When building: add the two transaction type constants, route both movements through `InventoryService` inside one `DB::transaction`.

---
**Related documents**: [Costing & Inventory Rules](../07-business-rules/costing-and-inventory-rules.md), [ADR-007](../01-architecture/decisions/ADR-007-nullable-warehouse-on-line-items.md), [Products & Units](products-and-units.md).
**Future improvements**: dedicated PURCHASE transaction types; `lockForUpdate` on stock checks; low-stock alerts (pairs with Notifications roadmap).
**Open questions**: should manual adjustments require a reason field for audit?
**Last review checklist**: [ ] type table matches `InventoryTransaction` constants, [ ] invariant violations list current. Last reviewed: 2026-07-08.
