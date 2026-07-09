# Roadmap

One file, honestly tiered. The rule from the project's own operating philosophy: **nothing beyond the current phase gets designed in detail.** Speculative documents rot; this list only commits to sequence.

## Done

- **Phase 1** — customers, products, orders, payments, ledger.
- **Phase 2** — warehouses, inventory, inventory transactions.
- **Phase 2.5 (grew organically, now real)** — suppliers, purchase orders + weighted-average costing, supplier payments, supplier-product intelligence, price tiers, dual units, discounts, refunds, walk-in customers, daily report, search, dashboard, AI endpoints (describe-product, insights, chat).

## Now — Phase 3

Auth hardening & roles (Sanctum in place; role *enforcement* pending), middleware/policies for `tenant_admin` / `store_manager` / `store_staff`, and **stock transfers** (rules pre-locked — see [inventory-and-warehouses.md](../06-domain/inventory-and-warehouses.md#phase-3-preview--stock-transfers-not-built)). Definition of done includes: role-boundary feature tests, transfer atomicity tests, `docs/06-domain/stock-transfers.md` written.

## Next (ordered, not scheduled)

1. **Hardening pass**: the four known defects in the [AI guide](../09-ai-collaboration/ai-collaboration-guide.md#known-open-defects-fix-only-when-asked-dont-be-surprised-by-them), token expiry policy, `lockForUpdate` on stock, reconciliation command.
2. **Reports v2**: profit (requires cost snapshot on order items first), per-store views.
3. **Notifications**: low-stock alerts, debt reminders — first async/queue work in the project.

## Later (one line each, deliberately undesigned)

- **Mobile apps** — Sanctum tokens already support this; no backend redesign expected.
- **Employees module** — beyond the three roles; wait for a real requirement.
- **AI expansion** — insights are seeded (`AIService`); grow from real usage, not speculation.
- **Multi-tenant onboarding / billing** — the moment a second paying tenant exists, this jumps the queue.
- **White-label / Enterprise** — not before product-market fit. No design work permitted yet (this sentence is the design work).

---
**Related documents**: [Philosophy](../00-overview/product-and-engineering-philosophy.md) (anti-goals), [AI guide](../09-ai-collaboration/ai-collaboration-guide.md).
**Future improvements**: attach rough dates once Phase 3 ships.
**Open questions**: sequencing of Notifications vs Reports v2 — decide by what the business owner asks for first.
**Last review checklist**: [ ] Done section matches code, [ ] Now matches actual work, [ ] Later still one-liners. Last reviewed: 2026-07-08.
