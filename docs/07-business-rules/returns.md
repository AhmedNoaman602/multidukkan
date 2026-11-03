# Returns — Customer & Supplier

**Status: Draft spec, not built.** Business rules are confirmed by the owner (form answers, 2026-11-02/03). Items marked **Proposed** are our design choices and need review before code.

Two flows that look alike but are separate:
- **Customer return** — goods come back from a customer; the shop owes value back.
- **Supplier return** — goods go back to a supplier; the supplier owes value back.

## Part A — Customer returns

### Business rules (confirmed)

| # | Rule |
|---|---|
| A1 | Any role can create a customer return, in any store they can sell in. No time limit, no approval for large returns. |
| A2 | If the customer has unpaid invoices, the return's value **first settles them**, oldest invoice first. |
| A3 | What is left after A2: <br>• **Customer had debts** → paid **in cash** if the shop has the money; if not, it is added to the **customer's credit**. <br>• **Customer had no debts** → the **customer chooses** cash or credit. |
| A4 | Staff can create returns, but **only the owner or a manager can give cash**. |
| A5 | Each returned line is marked **good** (back to the shelf, sellable) or **damaged** (goes to *pending settlement*). |
| A6 | Damaged goods in pending settlement **still count in stock value**. Only the **owner** decides what happens to them: back to the shelf, back to the supplier, or written off. |

Worked example (A2–A4): return worth 500, customer owes 300 on old invoices → 300 settles those invoices, oldest first; the remaining 200 is paid in cash by the owner or a manager if the shop has the money, otherwise it is added to the customer's credit.

### Design (Proposed)

**C1 — A return always points at the original invoice.** You pick the order, then the lines and quantities. Quantity per line ≤ sold − already returned (in base units). This gives the price the customer actually paid and stops returning more than was sold.

**C2 — Value per unit = the price paid, after the order's discount.**
`unit_value = unit_price × (order.total ÷ items subtotal)`, return total rounded once to the piaster (same rule as [financial-calculations.md](financial-calculations.md)). Without this, returning goods from a discounted order would pay back more than the customer paid. Total returned on an order can never exceed `order.total`.

**C3 — The original invoice is never edited.** Rejected alternative: lowering the item quantity with `adjustItem`/`adjustOrderCharge`. It rewrites what was sold, and it is locked on fully-paid orders — exactly the common return case.

**C4 — Money reuses the existing payment paths, no new balance formula.** In one transaction, through `LedgerService`:
1. Allocate the value FIFO over the customer's unpaid orders (the original order included, if it is unpaid) — the same allocation as `processAutoPayment`, but with a non-cash method `return`. Each allocation = a `Payment` row + `PAYMENT` entry.
2. Remainder → `CREDIT_APPLY` (customer credit).
3. If cash was chosen → pay that credit straight out (C5).

All entries carry `reference_type = 'customer_return'`. `return` payments must count as **non-cash** everywhere `cashReceived` / "collected" is summed.

Why not `REFUND` for the cash part: `REFUND` fixes the balance but not the credit pool — `getCreditBalance` is `Σ CREDIT_APPLY − Σ CREDIT_CONSUMED` and ignores `REFUND`, so the customer would still show credit they already took in cash.

**C5 — Cash = "pay out credit", and it needs no waiting state.** One new ledger type `CREDIT_PAYOUT` (customer **debit**): cash handed over from the customer's credit. It is added to the debit side of the customer balance **and** subtracted in the credit pool (`Σ CREDIT_APPLY − Σ CREDIT_CONSUMED − Σ CREDIT_PAYOUT`), capped at the available credit — the only formula change in this spec; update [financial-calculations.md](financial-calculations.md) and [ledger.md](../06-domain/ledger.md) in the same PR. Staff returns always end as credit. The owner/manager either makes the return themselves and chooses cash (credit applied, then paid out, in the same transaction), or later pays out a customer's credit as its own action. Rejected alternative: a *pending approval* status on returns — a state machine and an inbox for a rare case. "Pay out credit" is also the open question already listed in [payments-and-credit.md](../06-domain/payments-and-credit.md).

**C6 — Walk-in customer:** no credit, so a walk-in return is cash only, so only owner/manager can make it.

**C7 — Cancelling an order that has a return is blocked.** Unwinding both together is a rare edge with real risk of double-counting.

### Stock (Proposed)

- **Good** lines → back to the order's store **shelf** (`restoreStock`, `TYPE_RETURN`, reference the return).
- **Damaged** lines → the store's **pending-settlement location**: a third location type `returns`, one per store, created with the store like the shelf. Sales only take from the shelf ([ADR-010](../01-architecture/decisions/ADR-010-stock-locations-and-shelf-fulfillment.md)), so this stock can't be sold by accident, yet it is still counted in stock value (A6).
- From pending settlement (owner only):
  - back to shelf → a normal transfer;
  - back to supplier → a supplier return (Part B) from this location;
  - write off → `ADJUSTMENT_OUT` with the cost snapshotted (see Reports).
- Needs a **new ADR** (adds a location type beside ADR-010's shelf/storage), and manual transfers out of the `returns` location must be owner-only.

## Part B — Supplier returns

### Business rules (confirmed)

| # | Rule |
|---|---|
| B1 | Returns are valued at **that purchase invoice's price** for the line. |
| B2 | The owner can **change the amount** — he may agree a deal price with the supplier by phone. |
| B3 | If the supplier was already paid, the money comes back as **cash**, a **deduction from the next invoice**, or **replacement goods** — all three allowed. |

Supplier returns are owner-only, like purchase orders and cost data (`PurchaseOrderPolicy`, `view-cost-data`).

### Design (Proposed)

**S1 — A supplier return points at the purchase order line.** Quantity ≤ received − already returned, and ≤ stock at the chosen source location (shelf, storage or pending settlement). Default amount = `quantity × line price` (base-unit price, 4 decimals); the owner may edit it **before saving**. After saving, a correction is a new return or reversal, never an edit ([ADR-006](../01-architecture/decisions/ADR-006-ledger-mutability-boundaries.md)).

**S2 — Ledger:** new supplier entry `SUPPLIER_RETURN`, direction **credit** (we owe the supplier less). Balance math is direction-based, so the formula is unchanged; the type is added to `LedgerEntry::TYPES` + the enum.

**S3 — The three settlements (B3) need almost nothing new:**
- **Deduct from next invoice** → nothing to do. The supplier balance goes negative (supplier owes us) and the next `PURCHASE_CHARGE` offsets it automatically.
- **Replacement goods** → recorded as a normal purchase order; its charge offsets the credit the same way.
- **Cash back** → new entry `SUPPLIER_REFUND`, direction **debit** (money received from the supplier), capped at what the supplier owes us.

**S4 — Stock:** deduct from the source location, `TYPE_PURCHASE_OUT`, reference the supplier return.

## Cost and profit (Proposed)

**P1 — Returns never change the average cost.** Rejected alternative: removing the returned pieces from the average at the invoice price, `(S × avg − q × p) ÷ (S − q)` — it jumps or goes negative when little stock is left, and [ADR-008](../01-architecture/decisions/ADR-008-weighted-average-costing.md) already chose not to rewind the average on PO cancellation. Same choice, same reason.

**P2 — Every return line snapshots `unit_cost` = the product's average cost at that moment.** That makes gains and losses exact later, even after the average moves.

**P3 — What the reports show:**
- **Net sales** = sales − customer returns (by return date).
- **Customer return, good** → cost of goods sold goes down by `qty × unit_cost` (the goods are back in stock).
- **Customer return, damaged** → same as good while pending; a **write-off** later is a stock loss of `qty × unit_cost`.
- **Supplier return** → gain or loss = `amount − qty × unit_cost`. Example: 10 pieces, average cost 7 (value 70), supplier gives back 50 → **loss 20**. If the supplier gives back 75 → gain 5.

## Permissions summary

| Action | Owner | Manager | Staff |
|---|---|---|---|
| Customer return → credit | ✅ | ✅ | ✅ |
| Customer return → cash / pay out credit | ✅ | ✅ | ❌ |
| Decide pending-settlement goods | ✅ | ❌ | ❌ |
| Supplier return, edit amount, supplier cash back | ✅ | ❌ | ❌ |

## Build order (Proposed)

1. Customer return → credit only (C1–C4, good lines to the shelf). Smallest useful slice; covers the father's daily case.
2. Cash (pay out credit, C5) + walk-in (C6).
3. Damaged → pending-settlement location (needs the ADR) + owner decisions.
4. Supplier returns (Part B).
5. Report lines (P3).

---
**Related documents**: [Payments & Credit](../06-domain/payments-and-credit.md), [Ledger](../06-domain/ledger.md), [Order Lifecycle](order-lifecycle.md), [Costing & Inventory Rules](costing-and-inventory-rules.md), [Suppliers & POs](../06-domain/suppliers-and-purchase-orders.md).
**Open questions**: returns without an invoice (C1 requires one) — ask if it happens in the shop; can a return be undone, or is the correction a new sale?
**Last review checklist**: [ ] Proposed items approved or changed, [ ] matches code once built. Written: 2026-11-03.
