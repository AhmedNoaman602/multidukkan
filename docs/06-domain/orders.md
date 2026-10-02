# Orders & Order Items

An order is a **sale event**: an immutable-ish snapshot of what was sold, to whom, at what price, plus a charge on the customer's ledger. Full step-by-step flows live in [order-lifecycle.md](../07-business-rules/order-lifecycle.md); this doc is the entity reference.

## Orders — schema highlights

| Column | Meaning | Rules |
|---|---|---|
| `invoice_number` | `YYYY-NNN` per tenant/year | Generated in `OrderService::generateInvoiceNumber` (includes trashed orders in the max-lookup). Known 999/year collision defect — see [domain README](README.md#cross-cutting-schema-notes) |
| `customer_name_snapshot` | Name at sale time | Survives customer edits/deletes |
| `discount` | Order-level absolute discount | Clamped to `[0, items_total]`. Always `0` while `manual_total` is set |
| `manual_total` | Owner's override of the charge, nullable | `null` = no override. Mutually exclusive with `discount`: setting it zeroes the discount; a later discount or any item edit clears it back to `null` |
| `total` | **Stored** final charge | Written at creation; afterwards ONLY via `LedgerService::adjustOrderCharge` ([ADR-004](../01-architecture/decisions/ADR-004-stored-order-total.md)). Equals `manual_total` when one is set — the owner can charge any amount regardless of computed items total |
| `order_date` | Optional backdating | Sets `created_at` directly (timestamps temporarily disabled). Reports and FIFO payment ordering use `created_at`, so backdated orders sort historically — intended |
| `created_by` | User who made the sale | |
| `deleted_at` | Soft delete = **cancelled** | Cancellation ≠ removal; see lifecycle doc |

**Status is never stored.** `Order::isSettled()` (all payments incl. store-credit ≥ total) and `Order::cashReceived()` (real money only, excludes `is_auto_reversible` credit payments) are the two distinct questions — don't conflate them. "Unpaid" queries use `scopeWhereUnpaid` (SQL subquery on payments net of refunds).

## Order items — schema highlights

`product_id` (FK for analytics) + `product_name` (snapshot), `quantity`, `unit_type` (`base`|`secondary`), `unit_price` (snapshot, tier-resolved or overridden, already converted for secondary units), `warehouse_id` (NOT NULL — the location the line's stock left from, always the store's shelf for new sales; the API prohibits it in payloads, [ADR-010](../01-architecture/decisions/ADR-010-stock-locations-and-shelf-fulfillment.md)).

**Line merging**: at creation, lines with identical (`product_id`, `unit_type`, `conversion_factor`) merge into one row by summing quantity; `addItem` merges into an identical line at the shelf. Different unit types stay separate rows. Stock need is summed **per product** across lines (1 box + 5 pcs = one need of 17).

## Mutation surface (all in `OrderService`)

| Action | Endpoint | Money effect | Stock effect |
|---|---|---|---|
| Create | `POST /orders` | `ORDER_CHARGE`; auto credit-consume; optional `pay_immediately` cash payment | `StockFulfillmentService::fulfill` at the shelf: refill from storage if short (replenishment transfer), then `SALE` |
| Update header | `PATCH /orders/{o}` | `manual_total` → discount 0 + `adjustOrderCharge(manual_total)`; discount change → clear `manual_total`, recompute + `adjustOrderCharge` | none |
| Adjust item | `PATCH /orders/{o}/items/{i}` | Clear `manual_total`, recompute + `adjustOrderCharge` | Increase: `fulfill` at the line's location / decrease: restore there |
| Add item | `POST /orders/{o}/items` | Clear `manual_total`, recompute + `adjustOrderCharge` | `fulfill` at the shelf |
| Cancel | `DELETE /orders/{o}` | Blocked if unrefunded cash payments; restores credit; `REVERSAL` for cash portion | Restore every line to its recorded location; replenishments stay |

---
**Related documents**: [Order Lifecycle](../07-business-rules/order-lifecycle.md), [Payments & Credit](payments-and-credit.md), [Ledger](ledger.md).
**Future improvements**: fix invoice-number collision; consider `manual_total` audit trail (who overrode, from what).
**Open questions**: `adjustItem` runs outside `DB::transaction` while `addItem` is wrapped — inconsistency worth fixing next time the file is touched.
**Last review checklist**: [ ] mutation table matches `OrderService` + routes, [ ] status helpers unchanged. Last reviewed: 2026-07-08.
