# ADR-005: Snapshot name + price on line items at transaction time

**Status**: Accepted **Date**: recorded 2026-07-08 (decision predates record)

## Context
Products change: prices move, names get edited, products get deleted. An invoice from March must forever show what was actually sold at the price actually charged, regardless of what the product row looks like today.

## Decision
At creation time, line items copy the facts they need:
- `order_items`: `product_name`, `unit_price` (tier-resolved or manually overridden), `unit_type`, `quantity`.
- `purchase_order_items`: `product_name`, `unit_price`, `unit_type`, `quantity`, `total`.
- `orders`: `customer_name_snapshot`. `purchase_orders`: `supplier_name_snapshot`.

Line items keep a `product_id` FK for analytics/joins, but **rendering an invoice must never join through to live product data for name or price**.

## Alternatives rejected
- **Join to products at read time**: breaks historical invoices on every price change; breaks entirely on product deletion.
- **Full product JSON snapshot**: overkill; we only need name and price. Add columns if a new field becomes historically relevant (e.g. tax rate, someday).

## Consequences
- Product deletion is safe for order history (order items keep the name).
- Reports on "revenue by product" join via `product_id` and tolerate deleted products.
- Editing a line item's `unit_price` after the fact is an explicit business action (`OrderService::adjustItem`), which re-syncs the ledger via `adjustOrderCharge` — the snapshot is mutable by intent, never by side-effect.

---
**Related**: [Orders](../../06-domain/orders.md), [ADR-004](ADR-004-stored-order-total.md). **Open questions**: none. **Last reviewed**: 2026-07-08.
