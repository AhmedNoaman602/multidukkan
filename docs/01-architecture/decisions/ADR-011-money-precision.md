# ADR-011: Prices keep 4 decimals, totals are rounded to piasters

**Status**: Accepted **Date**: 2026-10-28 (owner's decision; W7)

## Context
Every money column was `decimal(10,2)`. The shop sells cheap items by the piece that it buys by the box: 1000 nails for 48.50 cost 0.0485 each. With 2 decimals that cost was stored as 0.05 (a box then "cost" 50.00), the error carried into the next weighted average ([ADR-008](ADR-008-weighted-average-costing.md)), and a piece cost under 0.005 became 0.00, so profit reports showed 100% margin. A piece price like 1.2345 couldn't be entered at all.

But nobody pays in fractions of a piaster. If totals kept 4 decimals, 3 × 1.2345 = 3.7035 paid with 3.70 would leave 0.0035 owed forever, or a cash 3.71 would leave 0.0065 of credit.

## Decision
Two scales, named in `App\Support\Money`:

- **`PRICE_SCALE = 4`** — per-piece amounts: `products.price`, `price_a`–`price_e`, `cost_price`; `supplier_products.cost_price`, `last_purchase_price`; `order_items.unit_price`; `purchase_order_items.unit_price`. Columns `decimal(12,4)`, rules `decimal:0,4`. The weighted-average cost and `OrderService::priceKey` (line merging, MUL2-26) use this scale.
- **`TOTAL_SCALE = 2`** — everything that is charged, paid or owed: `orders.total`, `discount`, `manual_total`, `payments`, `ledger_entries`, `purchase_orders.total`, `purchase_order_items.total`, `expenses`, `supplier_payments`. Columns stay `decimal(10,2)`, rules `decimal:0,2`.

**The total is rounded once, to the nearest piaster, and the fraction is dropped.** It never becomes a credit or a debt: the ledger only ever sees the rounded total, so a customer who pays it is settled with a balance of exactly 0. Line amounts (`unit_price × quantity`) are summed unrounded, then the order or purchase total is rounded (`round(subtotal − discount, 2)`). Nearest piaster (half up), the rounding the code already used: 3.7035 → 3.70, 3.7055 → 3.71.

## Alternatives rejected
- **4 decimals everywhere** (the first W7 draft): balances would carry fractions nobody can pay; needs a write-off rule and a new ledger entry type. The owner rejected it.
- **Always cut the fraction down** (3.7055 → 3.70): the owner was fine with either; nearest was kept because every total already rounds that way. A one-line change in the total formulas if it's ever wanted.
- **Round each line to piasters before summing**: the invoice total would depend on how lines are split (three lines of 1.2345 → 3.69 vs one line of 3 → 3.70).
- **Store prices in integer milli-piasters**: exact, but a rewrite of every money read and write for no visible gain at this scale.

## Consequences
- Old prices and costs are unchanged by the migration (2.50 → 2.5000). Costs already rounded to 2 decimals stay rounded; new purchases average from them, so the error fades as stock is bought again.
- The sum of an invoice's line amounts can differ from its total by less than a piaster. The total (and the ledger) is the truth; line amounts are display.
- `purchase_order_items.total` is rounded per line while `purchase_orders.total` rounds the sum, so the two can differ by a piaster per line. The PO total is what the supplier is owed.
- The API returns prices with 4 decimals (`"0.0485"`, `"2.5000"`); the frontend shows prices "2 to 4 decimals as needed" and totals with 2.
- Rolling the migration back rounds every price and cost to 2 decimals (lossy).

---
**Related**: [Financial calculations](../../07-business-rules/financial-calculations.md), [ADR-005](ADR-005-snapshot-pricing.md), [ADR-008](ADR-008-weighted-average-costing.md). **Last reviewed**: 2026-10-28.
