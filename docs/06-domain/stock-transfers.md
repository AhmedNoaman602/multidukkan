# Stock Transfers

A stock transfer moves base-unit stock between two locations of **the same store** — shelf ← storage, shelf → storage, or storage → storage. It is an inventory-only event: **zero ledger entries**. Why stores, shelves and storage locations look the way they do is recorded in [ADR-010](../01-architecture/decisions/ADR-010-stock-locations-and-shelf-fulfillment.md).

## Schema

- `stock_transfers`: `tenant_id`, `store_id`, `from_warehouse_id`, `to_warehouse_id` (both `RESTRICT`), `type` (`manual` | `replenishment`), `status`, `order_id` (nullable — replenishment only), `created_by`, `notes`, `batch_id`, `completed_at`. Indexed on `(tenant_id, store_id, created_at)`.
- `stock_transfer_items`: `product_id`, `quantity` **in the entered unit**, plus the unit snapshot `unit_type`, `conversion_factor`, `unit_name` — the same shape as order lines. `baseQuantity()` = quantity × conversion_factor.
- Stock history: one `TRANSFER_OUT` row at the source and one `TRANSFER_IN` row at the destination per line, both with `reference_type = StockTransfer`, `reference_id` = the transfer, and the transfer's `batch_id`.

## Types

| Type | Who creates it | Status | Built |
|---|---|---|---|
| `manual` | `tenant_admin`, or the `store_manager` of the source store | `COMPLETED` immediately | ✅ Part 3 |
| `replenishment` | The system, during a sale whose shelf is short; linked to the order | `COMPLETED` immediately, no approval | Part 4 |

`store_staff` cannot create manual transfers (403). The staff request → manager approval flow (`PENDING` / `APPROVED` / `REJECTED`) is deferred; it adds status transitions on the same tables. `POST /stock-transfers` creates manual transfers only: sending `type`, `status` or `order_id` returns 422 (`prohibited`), so a replenishment can't be forged.

## Flow (manual)

`POST /stock-transfers` → `StoreStockTransferRequest` (tenant ownership, source ≠ destination, destination in the source's store via `WarehouseInStore`, distinct products, integer quantity ≥ 1) → policy `createFrom` (role + source store) → `StockTransferService::transfer`, one `DB::transaction`:

1. Create the header (`manual`, `COMPLETED`, new `batch_id`).
2. Save each line with its unit resolved by `Product::factorFor()` / `unitNameFor()`.
3. For each line, in product-id order: `InventoryService::transferStock()` — create the destination row if missing (threshold 0), lock both rows in id order, 422 if the source is short, decrement, increment, write the two history rows.

Any failure rolls back everything: no header, no lines, no history, no quantity change.

## Reading transfers

- `GET /stock-transfers` — paginated (20), newest first. Store users see their own store; admins see all and may filter by `store_id`. Filters: `warehouse_id` (either side), `product_id`, `type`.
- `GET /stock-transfers/{id}` — admins, or users of the transfer's store.
- Activity feed: the OUT and IN rows of a transfer share a batch, so the feed shows **one** `TRANSFER` event whose quantity counts only the OUT side.

---
**Related documents**: [Inventory & Warehouses](inventory-and-warehouses.md), [API contract change: prohibited fields](stock-transfers-api-contract.md), [Part 3 study guide: old vs new](part-3-stock-transfer-changes.md), [ADR-010](../01-architecture/decisions/ADR-010-stock-locations-and-shelf-fulfillment.md). **Open questions**: none. **Last reviewed**: 2026-10-01.
