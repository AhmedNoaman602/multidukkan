# Returns — Customer & Supplier

**Status: not implemented.** The application has no customer or supplier returns yet: there are no return endpoints, tables, ledger entry types or screens.

This document will describe returns **as they are actually shipped**. It is updated in the same pull request as each piece of returns functionality, and only for behavior that is implemented and verified. Until then, nothing here should be read as a description of how the system works.

How the existing code handles related cases today:
- Cash refunds against a customer's payments: [Payments & Credit](../06-domain/payments-and-credit.md).
- Cancelling an order or a purchase order: [Order Lifecycle](order-lifecycle.md), [Suppliers & POs](../06-domain/suppliers-and-purchase-orders.md).

---
**Related documents**: [Payments & Credit](../06-domain/payments-and-credit.md), [Ledger](../06-domain/ledger.md), [Financial Calculations](financial-calculations.md), [Order Lifecycle](order-lifecycle.md), [Costing & Inventory Rules](costing-and-inventory-rules.md), [Suppliers & POs](../06-domain/suppliers-and-purchase-orders.md).
**Future improvements**: filled in as returns functionality ships.
**Open questions**: none recorded here.
**Last review checklist**: [ ] every statement matches shipped code, [ ] nothing planned is described as built. Last reviewed: 2026-11-08.
