# ADR-004: `orders.total` is stored, synced only via `LedgerService::adjustOrderCharge`

**Status**: Accepted **Date**: ~2026-05-17 (migration `add_total_to_orders_table`), recorded 2026-07-08
**Supersedes**: the original project rule "Never store `order.total` — calculate from order_items".

## Context
The original rule derived totals from `order_items` at read time to guarantee consistency. In practice, every order list, report, unpaid-orders query and dashboard needed the total, forcing repeated `SUM(unit_price * quantity)` subqueries — directly against the product's #1 requirement (speed). Additionally, the business needed `manual_total` (owner overrides the computed total at sale time), which a purely derived value cannot represent.

## Decision
`orders.total` is a stored column. Two invariants make this safe:
1. **Write funnel**: any mutation that changes an order's effective total (item adjust/add, discount change) goes through `LedgerService::adjustOrderCharge(Order $order, float $newTotal)`, which updates the `ORDER_CHARGE` ledger entry **and** `orders.total` together. No other code path writes `orders.total` after creation.
2. **The ledger remains authoritative** for balances; `orders.total` is a performance cache that must equal the order's `ORDER_CHARGE` amount at all times.

## Alternatives rejected
- **Keep deriving**: measured cost too high across lists/reports; blocks `manual_total`.
- **MySQL generated column**: cannot represent `manual_total` or discounts applied at charge level.

## Consequences
- **For AI sessions**: the old rule still appears in older docs/comments. Do NOT "fix" code back to derived totals, and do NOT write `orders.total` directly — use `adjustOrderCharge`.
- Divergence between `orders.total` and the `ORDER_CHARGE` entry is a data bug; a reconciliation check (artisan command comparing the two) is a cheap future safeguard.
- `order.status` is **still never stored** — always derived from payments (`Order::isSettled()` / `settledAmount()`). This ADR changes only `total`.

---
**Related**: [ADR-003](ADR-003-ledger-single-source-of-truth.md), [Order Lifecycle](../../07-business-rules/order-lifecycle.md). **Future improvements**: reconciliation artisan command. **Last reviewed**: 2026-07-08.
