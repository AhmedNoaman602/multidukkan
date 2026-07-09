# Financial Calculations — The Complete Formula Reference

Every money formula in the system, with its single authoritative implementation. If you need one of these numbers, **call the listed method** — reimplementing any formula below is the project's cardinal sin (it shipped a production bug once; see [ADR-003](../01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md)).

## Balances (implementation: `LedgerService`)

| Number | Formula | Method |
|---|---|---|
| Customer balance | `Σ(ORDER_CHARGE + CREDIT_CONSUMED + REFUND) − Σ(PAYMENT + CREDIT_APPLY + REVERSAL)` | `getBalance` / batch `getBalancesForCustomers` |
| Customer credit | `max(0, Σ CREDIT_APPLY − Σ CREDIT_CONSUMED)` | `getCreditBalance` |
| Supplier balance | `Σ direction=debit − Σ direction=credit` (entity_type=supplier) | `getSupplierBalance` / batch `getBalancesForSuppliers` |

Sign convention: **positive customer balance = customer owes the store**; negative = store owes the customer (available credit line). Positive supplier balance = tenant owes the supplier. All balances `round(…, 2)`.

## Order money (implementations: `OrderService`, `PaymentService`, `Order` model)

| Number | Formula | Where |
|---|---|---|
| Items subtotal | `Σ(unit_price × quantity)` over order_items | `OrderService` / SQL `SUM(unit_price * quantity)` |
| Effective discount | `max(0, min(discount, subtotal))` | `OrderService::createOrder` |
| Charge amount (order total) | `manual_total` if provided, else `round(subtotal − discount, 2)` | `OrderService::createOrder`; later changes only via `adjustOrderCharge` |
| Payment net value | `amount − COALESCE(refunded_amount, 0)` | everywhere payments are summed |
| Settled amount | `Σ net value` of ALL payments (incl. credit) | `Order::settledAmount()` |
| Is settled | `settledAmount ≥ total` | `Order::isSettled()` |
| Cash received | `Σ net value` where `is_auto_reversible = false` | `Order::cashReceived()` |
| Order owed | `max(0, orderTotal − alreadyPaid)` where orderTotal recomputed from items − discount | `PaymentService` (all three paths) |
| Unpaid order filter | `total > Σ net payments` (SQL subquery) | `Order::scopeWhereUnpaid` |

**Settled vs cash received are different questions.** An order fully paid by store credit is settled but produced zero cash. Dashboards/reports about cash flow use `cashReceived`; debt logic uses `settledAmount`.

## Allocation rules

- **FIFO everywhere**: excess direct payments, auto-payments, and whole-order refunds all distribute oldest-first (`created_at asc` for orders, `id asc` for an order's payments). No proportional splitting anywhere.
- **Credit auto-consumption at order creation**: `applyAmount = min(creditAvailable, chargeAmount)` where `creditAvailable = max(0, −balanceBefore)`. Writes a `credit`-method Payment (+`PAYMENT` entry) **and** a `CREDIT_CONSUMED` entry of the same amount.
- **Overpayment**: direct-payment excess first cascades FIFO to other unpaid orders; the true leftover becomes `CREDIT_APPLY` (overpayment credit). Auto-payment leftover becomes `CREDIT_APPLY` (manual credit).

## Refund caps

- Per payment: `amount ≤ payment.amount − refunded_amount`; credit payments never cash-refundable.
- Per order: `amount ≤ Σ net cash payments` on the order.

## Costing

Weighted average per PO line, base units, running per-product chain within a PO:
`new_avg = (stock × current_cost + purchased_qty × line_price) / (stock + purchased_qty)`; zero-denominator fallback → `line_price`. Details + rejected alternatives: [ADR-008](../01-architecture/decisions/ADR-008-weighted-average-costing.md). **Profit** is therefore as-of-now (`price − cost_price`); historical per-order profit is not yet computable (cost isn't snapshotted on order items — known limitation).

## Taxes

There are none. No VAT/tax fields exist anywhere. If taxes ever enter scope, they must be snapshotted per line (rate at sale time) — see [ADR-005](../01-architecture/decisions/ADR-005-snapshot-pricing.md) reasoning.

---
**Related documents**: [Ledger](../06-domain/ledger.md), [Payments & Credit](../06-domain/payments-and-credit.md), [Order Lifecycle](order-lifecycle.md).
**Future improvements**: snapshot cost on order items → true profit reports; reconciliation command (`orders.total` vs `ORDER_CHARGE`).
**Open questions**: none the code doesn't already answer.
**Last review checklist**: [ ] every formula spot-checked against its listed method. Last reviewed: 2026-07-08.
