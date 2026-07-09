# The Ledger — Financial Source of Truth

`ledger_entries` is the single record of every financial event between the tenant and its customers **and** suppliers. All balance math flows from it, through `LedgerService` only ([ADR-003](../01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md)). If the ledger and any other number disagree, the ledger wins and the other number is the bug.

## Two schemas in one table (important!)

The table serves two generations of design:

| | Customer entries (original) | Supplier entries (newer) |
|---|---|---|
| Identified by | `customer_id` set | `entity_type='supplier'`, `entity_id`, (`supplier_id` also set) |
| Debit/credit determined by | **`type` whitelist** (see below) | **`direction` column** (`debit`/`credit`) |
| Balance method | `getBalance` / `getBalancesForCustomers` | `getSupplierBalance` / `getBalancesForSuppliers` |

Do not mix idioms: customer entries don't use `direction`; supplier math doesn't inspect `type`. A future unification (moving customers to `entity_type`/`direction`) would be welcome but is a deliberate migration project, not a drive-by refactor.

## Entry types (`LedgerEntry::TYPES` + DB enum — both must be updated to add one)

| Type | Side (customer) | Created by | Meaning |
|---|---|---|---|
| `ORDER_CHARGE` | debit | `chargeOrder` | Customer owes for an order |
| `PAYMENT` | credit | `applyAmount` | Money (or applied credit) reduced the debt |
| `CREDIT_APPLY` | credit | `applyCreditOverPayment`, `addCredit`, `restoreCredit` | Store now owes customer (overpayment / manual / restored on cancel) |
| `CREDIT_CONSUMED` | debit | `consumeCredit` | Customer's credit was spent on an order (pairs with a `PAYMENT` of equal amount) |
| `REVERSAL` | credit | `reverseOrder` | Order cancelled — cash portion of the charge undone |
| `REFUND` | debit | `issueRefund` | Cash handed back to the customer |
| `PURCHASE_CHARGE` | debit (supplier dir.) | `purchaseCharge` | Tenant owes supplier for a PO |
| `SUPPLIER_PAYMENT` | credit (supplier dir.) | `applySupplierPayment` | Tenant paid the supplier |
| `PURCHASE_REVERSAL` | credit (supplier dir.) | `reversePurchaseOrder` | PO cancelled |

## The balance formulas (documented; the code in `LedgerService` is authoritative)

```
customer_balance = Σ(ORDER_CHARGE, CREDIT_CONSUMED, REFUND) − Σ(PAYMENT, CREDIT_APPLY, REVERSAL)
                   > 0 → customer owes store; < 0 → store owes customer (credit line)

customer_credit  = max(0, Σ CREDIT_APPLY − Σ CREDIT_CONSUMED)

supplier_balance = Σ(direction=debit) − Σ(direction=credit)   > 0 → tenant owes supplier
```

Why `REFUND` is a debit: refunding cash reduces what the store owes the customer — it moves the balance back toward "customer owes". Why `CREDIT_CONSUMED` exists at all when a `PAYMENT` is also written: `PAYMENT` settles the *order*; `CREDIT_CONSUMED` decrements the *credit pool*. Removing either breaks a different formula.

## Mutability

Append-only with exactly two edit paths (`adjustPayment`, `adjustOrderCharge`) — boundaries and rationale in [ADR-006](../01-architecture/decisions/ADR-006-ledger-mutability-boundaries.md). No soft deletes, no hard deletes, ever.

## Reference columns

`reference_type` + `reference_id` point at the causing record. **Known inconsistency**: values mix conventions — `'order'`, `'payment'`, `'manual'`, `'supplier_payment'` (strings) vs `PurchaseOrder::class` (FQCN). The `reference()` morphTo only resolves FQCN entries. Standardize when next touched; until then, query by the string each writer actually used.

---
**Related documents**: [Financial Calculations](../07-business-rules/financial-calculations.md), [Payments & Credit](payments-and-credit.md), ADRs [003](../01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md)/[004](../01-architecture/decisions/ADR-004-stored-order-total.md)/[006](../01-architecture/decisions/ADR-006-ledger-mutability-boundaries.md).
**Future improvements**: unify customer entries onto `entity_type`/`direction`; standardize `reference_type` to FQCN morphs.
**Open questions**: store-level ledger filtering (`store_id` nullable since 2026-03-28) — is per-store P&L a requirement?
**Last review checklist**: [ ] type table matches `LedgerEntry::TYPES` and enum migrations, [ ] formulas match `LedgerService`. Last reviewed: 2026-07-08.
