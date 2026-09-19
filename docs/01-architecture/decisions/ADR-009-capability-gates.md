# ADR-009: Named capability Gates for cross-cutting role rules

**Status**: Accepted **Date**: 2026-09-15

## Context

[ADR-002](ADR-002-string-role-column.md) left one question open: "Policies vs middleware for role checks — decide during Phase 3." Phase 3's first real case forced the answer.

Cost prices and everything derived from them (`cost_price`, the seven `profit_margin*` fields, supplier pricing) reached every authenticated user. A `store_staff` at the counter could read what the shop pays its suppliers and what it makes on every tier. The daily report — gross profit, net profit, expenses — was equally open.

These rules do not belong to one model. "May this user see cost figures?" applies to a Product resource, an Order's line items, and a supplier-pricing endpoint at once. A Policy answers questions about *a model*; this question is about *a capability*. Answering it per-model would mean the same rule written in three places — the shape of this project's founding bug ([ADR-003](ADR-003-ledger-single-source-of-truth.md)), in authorization form rather than money form.

## Decision

Cross-cutting role rules are **named Gates registered in `AppServiceProvider::boot()`**, one Gate per business capability. Two exist:

- `view-cost-data` — `tenant_admin` only. Governs `cost_price`, all seven `profit_margin*` fields, supplier records, supplier pricing, purchase orders, and supplier payments.
- `view-reports` — `tenant_admin` only. Governs `GET /reports/daily` in full.

**Amended 2026-09-17**: `view-cost-data` originally included `store_manager`. It no longer does — see Consequences.

Rules that follow:

- A Gate is the **business decision**; the `User` role helpers (`isTenantAdmin()`, `isStoreManager()`, `isStoreStaff()`) are the **role check**. Gates call the helpers; nothing else re-implements the role comparison.
- Never write `$user->role === ...` or `isTenantAdmin()` inline in a Resource or controller for a capability a Gate already names.
- **Barrier vs field filter**: when an entire endpoint is the sensitive data, enforce it as a barrier (`->middleware('can:...')` on the route, or `$this->authorize('...')` in the controller) → 403. When a sensitive field sits inside an otherwise-permitted response, filter the field so the **key is absent**, never `null`.
- Model-scoped questions ("may this user edit *this* product?") stay in Policies. Gates do not replace them.
- Capabilities are not nested. `view-cost-data` does not imply `view-reports`: the two answer different questions and are granted separately.

## Alternatives rejected

- **Policy methods (`ProductPolicy::viewCost`)**: cost visibility spans Product, Order items, and a supplier endpoint. A Policy is bound to one model, so this needs the rule written three times — exactly the duplication ADR-003 exists to prevent.
- **A single `view-financials` Gate for both cost and reports**: collapses two genuinely different audiences. Managers need cost to price and purchase; the reports page is the owner's P&L. One Gate forces them open or shut together.
- **Role middleware (`role:tenant_admin`) on routes**: encodes *who* instead of *why*. Every future role change becomes a sweep through `routes/api.php`; a named capability changes in one line.
- **A third "manager" permission tier**: speculative. Two capabilities cover every case the business has actually asked for; extract a third when a real requirement appears.
- **Returning hidden fields as `null`**: a `cost_price: null` next to a real price still tells the reader the field exists and invites the frontend to treat missing as zero. Absent is unambiguous.

## Consequences

- One line each defines who sees cost data and who sees reports. Changing either audience is a one-line change with test coverage behind it (`tests/Feature/CostDataVisibilityTest.php`).
- **`store_manager` has no access to cost data or reports (amended 2026-09-17).** The original decision granted managers `view-cost-data` on the assumption they price and purchase; they do neither — `PurchaseOrderPolicy::create` and every product write are `tenant_admin` only, so cost figures inform no action available to them. The concern that prompted the grant — a manager discounting below cost — is addressed by a discount limit, not by showing them the cost and trusting them to check. Consequence: a manager sees no cost, no margin, no supplier records, no purchase orders, no supplier payments and no daily report; every cost question routes to the owner. Reverting is one line in `AppServiceProvider::boot()` and the tests flip with it.
- The API response shape is now **role-dependent**: the frontend must treat `cost_price` and `profit_margin*` as optional keys rather than assuming presence. Any UI that renders them needs a guard for staff sessions.
- Margins go together or not at all. Each margin is `price − cost_price` beside a visible `price`, so exposing one recovers the cost by subtraction. Adding an eighth margin field means gating it too.
- Reports are all-or-nothing by design; the route is not split into public and private halves.
- Future cross-cutting rules (transfer approval, expense visibility) have a pattern to follow instead of a fresh argument.

---
**Related**: [ADR-002](ADR-002-string-role-column.md) (closes its open question), [ADR-003](ADR-003-ledger-single-source-of-truth.md) (the single-source-of-truth principle this applies to authorization), [Roadmap](../../10-roadmap/roadmap.md) (Phase 3).
**Open questions**: whether stock-transfer approval becomes a third Gate or stays Policy-bound — decide when transfers land.
**Last reviewed**: 2026-09-15.
