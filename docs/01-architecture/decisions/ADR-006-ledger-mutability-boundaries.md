# ADR-006: Ledger is append-only, with two narrow, deliberate edit exceptions

**Status**: Accepted **Date**: recorded 2026-07-08
**Amends**: the original rule "Ledger is append-only — never edit, only add reversals".

## Context
Append-only is the correct default for a financial ledger: history you can trust is history you cannot rewrite. But the business needed to *correct mistakes on the same business event* (cashier typed the wrong payment amount; an order item quantity was fixed) without polluting the customer's statement with reversal noise for what was a typo, not a transaction.

## Decision
Ledger entries are append-only, **except** two correction paths in `LedgerService`:
1. `adjustPayment(Payment, float, string)` — edits the `PAYMENT` entry's amount in place when a payment is corrected. Blocked if the payment has any `refunded_amount`.
2. `adjustOrderCharge(Order, float)` — edits the `ORDER_CHARGE` entry's amount in place when an order's items/discount change (see [ADR-004](ADR-004-stored-order-total.md)).

Everything else is appended: cancellations create `REVERSAL` / `PURCHASE_REVERSAL`, credit undo creates a new `CREDIT_APPLY`, refunds create `REFUND`. **No new edit paths may be added without a superseding ADR.** Deleting ledger entries is never allowed. (`ledger_entries` has no soft deletes by design — one narrow exception exists in order cancellation where auto-credit *payments* rows are deleted; the ledger entries themselves remain.)

## Alternatives rejected
- **Pure append-only (reversal + re-entry for every correction)**: statements become unreadable for merchants — a typo produces three lines. Rejected on product grounds.
- **Free editing**: destroys auditability; a disputed balance becomes unresolvable.

## Consequences
- Corrections are semantically "this event's amount was always X", not "a new event happened". This is a deliberate trade of forensic purity for statement readability.
- **For AI sessions**: do not add `update()`/`delete()` calls on `LedgerEntry` anywhere outside these two methods; do not "fix" these two methods to append reversals instead — both directions require a human decision.
- If auditors/enterprise customers ever require pure append-only, add an `amount_history` JSON or an edits audit table — superseding ADR required.

---
**Related**: [Ledger](../../06-domain/ledger.md), [ADR-003](ADR-003-ledger-single-source-of-truth.md), [ADR-004](ADR-004-stored-order-total.md). **Open questions**: should `adjustPayment` record the old amount somewhere for audit? **Last reviewed**: 2026-07-08.
