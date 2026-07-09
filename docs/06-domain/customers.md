# Customers

The customer record exists mostly to anchor **debt**. Most of what matters about a customer lives in the ledger, not on the customer row.

## Schema highlights

| Column | Meaning | Rules |
|---|---|---|
| `tenant_id` | Owner tenant | Required, always scoped |
| `code` | Human-friendly customer code | Added 2026-05; used for fast POS lookup |
| `price_tier` | `a`–`e` or `null` | Selects which product price applies at order time; `null` → base `price`. Tier resolution happens **at order creation** and is snapshotted into `order_items.unit_price` — changing a customer's tier never rewrites history |
| `is_walk_in` | Cash-counter pseudo-customer | Added 2026-06 for QuickSale flows; walk-ins shouldn't accumulate meaningful ledger debt |
| `area` | Geographic grouping | Reporting/delivery use |
| `created_by_store_id` | Which store first created them | **Tracking only — never a restriction.** Customers are tenant-wide; any store may sell to any customer |
| `deleted_at` | Soft delete | Orders keep `customer_name_snapshot`, so history survives deletion |

## Derived financial facts (never stored on the row)

- **Balance** (`> 0` = customer owes us): `LedgerService::getBalance()`; lists use `getBalancesForCustomers()` batched.
- **Credit** (store owes customer): `LedgerService::getCreditBalance()` = `CREDIT_APPLY − CREDIT_CONSUMED`, floored at 0.

Anti-pattern (the original production bug): computing customer debt from orders minus payments anywhere outside `LedgerService`.

## Key endpoints

`GET/POST/PUT/DELETE /customers`, plus financial sub-resources: `/customers/{c}/balance`, `/ledger` (history), `/summary`, `/credit` (manual credit add → `CREDIT_APPLY`), `/refund` (cash out → `REFUND`, see [payments-and-credit.md](payments-and-credit.md#refunds)).

## Business rules

1. A new order **auto-consumes available credit first** (see [order-lifecycle.md](../07-business-rules/order-lifecycle.md)) — the customer never pays cash while the store owes them money, unless credit is exhausted.
2. Deleting a customer does not touch their ledger — history is permanent; balance queries on deleted customers still work.
3. `CustomerObserver` handles lifecycle side-effects (e.g. code generation) — check it before adding creation logic elsewhere.

---
**Related documents**: [Ledger](ledger.md), [Payments & Credit](payments-and-credit.md), [Financial Calculations](../07-business-rules/financial-calculations.md).
**Future improvements**: define what happens to walk-in customers' residual balances (currently possible to create, meaningless to collect).
**Open questions**: should deletion be blocked while balance ≠ 0? Currently allowed.
**Last review checklist**: [ ] columns match migrations, [ ] tier resolution description matches `OrderService`. Last reviewed: 2026-07-08.
