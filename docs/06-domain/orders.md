# Orders & Order Items

An order is a **sale event**: an immutable-ish snapshot of what was sold, to whom, at what price, plus a charge on the customer's ledger. Full step-by-step flows live in [order-lifecycle.md](../07-business-rules/order-lifecycle.md); this doc is the entity reference.

## Orders — schema highlights

| Column | Meaning | Rules |
|---|---|---|
| `invoice_number` | `YYYY-NNN` per tenant/year | Generated in `OrderService::generateInvoiceNumber` (includes trashed orders in the max-lookup). Known 999/year collision defect — see [domain README](README.md#cross-cutting-schema-notes) |
| `customer_name_snapshot` | Name at sale time | Survives customer edits/deletes |
| `discount` | Order-level absolute discount | Clamped to `[0, items_total]` at creation |
| `total` | **Stored** final charge | Written at creation; afterwards ONLY via `LedgerService::adjustOrderCharge` ([ADR-004](../01-architecture/decisions/ADR-004-stored-order-total.md)). May be a `manual_total` override — the owner can charge any amount regardless of computed items total |
| `order_date` | Optional backdating | Sets `created_at` directly (timestamps temporarily disabled). Reports and FIFO payment ordering use `created_at`, so backdated orders sort historically — intended |
| `created_by` | User who made the sale | |
| `deleted_at` | Soft delete = **cancelled** | Cancellation ≠ removal; see lifecycle doc |

**Status is never stored.** `Order::isSettled()` (all payments incl. store-credit ≥ total) and `Order::cashReceived()` (real money only, excludes `is_auto_reversible` credit payments) are the two distinct questions — don't conflate them. "Unpaid" queries use `scopeWhereUnpaid` (SQL subquery on payments net of refunds).

## Order items — schema highlights

`product_id` (FK for analytics) + `product_name` (snapshot), `quantity`, `unit_type` (`base`|`secondary`), `unit_price` (snapshot, tier-resolved or overridden, already converted for secondary units), `warehouse_id` (nullable — null means no stock movement, [ADR-007](../01-architecture/decisions/ADR-007-nullable-warehouse-on-line-items.md)).

**Line merging**: at creation, and in `addItem`, lines with identical (`product_id`, `warehouse_id`, `unit_type`) merge into one row by summing quantity. Identical products at different warehouses or unit types stay separate rows — required for correct stock movement.

## Mutation surface (all in `OrderService`)

| Action | Endpoint | Money effect | Stock effect |
|---|---|---|---|
| Create | `POST /orders` | `ORDER_CHARGE`; auto credit-consume; optional `pay_immediately` cash payment | Check + deduct per warehoused line |
| Update header | `PATCH /orders/{o}` | Discount change → recompute + `adjustOrderCharge` | none |
| Adjust item | `PATCH /orders/{o}/items/{i}` | Recompute + `adjustOrderCharge` | Delta deduct/restore |
| Add item | `POST /orders/{o}/items` | Recompute + `adjustOrderCharge` | Check + deduct |
| Cancel | `DELETE /orders/{o}` | Blocked if unrefunded cash payments; restores credit; `REVERSAL` for cash portion | Restore all warehoused lines |

---
**Related documents**: [Order Lifecycle](../07-business-rules/order-lifecycle.md), [Payments & Credit](payments-and-credit.md), [Ledger](ledger.md).
**Future improvements**: fix invoice-number collision; consider `manual_total` audit trail (who overrode, from what).
**Open questions**: `adjustItem` runs outside `DB::transaction` while `addItem` is wrapped — inconsistency worth fixing next time the file is touched.
**Last review checklist**: [ ] mutation table matches `OrderService` + routes, [ ] status helpers unchanged. Last reviewed: 2026-07-08.
