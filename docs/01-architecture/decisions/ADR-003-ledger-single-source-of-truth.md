# ADR-003: LedgerService is the only source of financial truth

**Status**: Accepted **Date**: recorded 2026-07-08 (decision born from a production bug)

## Context
**A real bug shipped**: three controllers independently computed "amount owed" as `order.total - payments`, each slightly differently (refunds, credit payments, discounts handled inconsistently). Balances disagreed depending on which screen you looked at. For a product whose core value is trustworthy debt records, this was the worst possible class of bug.

## Decision
All balance/debt/credit math lives in `app/Services/LedgerService.php` and nowhere else:
- Single customer balance → `getBalance(int $tenantId, int $customerId)`
- Many customers (lists, dashboards) → `getBalancesForCustomers(...)` (batched, avoids N+1)
- Supplier balance → `getSupplierBalance(...)` / `getBalancesForSuppliers(...)`
- Customer credit → `getCreditBalance(...)`

If a financial number is needed and no method exists, **add the method to LedgerService**. Never inline the math.

## Alternatives rejected
- **Materialized balance column on customers**: fast reads but a second source of truth to keep in sync; the whole point is having exactly one.
- **DB view / generated column**: hides the formula from PHP, splits logic across two languages; revisit only if balance queries become a measured bottleneck.

## Consequences
- Every list showing balances must batch via `getBalancesForCustomers` — per-row `getBalance` calls are an N+1 (this bug also shipped once; fixed in commit `2abffa7`).
- The formula (which entry types are debits vs credits) is documented in [financial-calculations.md](../../07-business-rules/financial-calculations.md) and must stay in sync with `LedgerService`.

---
**Related**: [Ledger domain doc](../../06-domain/ledger.md), [Financial Calculations](../../07-business-rules/financial-calculations.md). **Open questions**: none — this is the most settled decision in the codebase. **Last reviewed**: 2026-07-08.
