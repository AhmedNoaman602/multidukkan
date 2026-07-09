# Product & Engineering Philosophy

## What MultiDukkan is

MultiDukkan is a **multi-tenant store management SaaS** for small Egyptian businesses: multi-store inventory, sales orders, customer debt tracking (the ledger), suppliers and purchasing. Backend is a Laravel REST API (this repo) consumed by a separate React frontend (`multidukkan-frontend`).

The first real customer is a real business (the founder's father's stores). This is not a demo — production data, real money, real debt records for real customers. Every design decision inherits from that fact.

## Product philosophy

1. **Speed is the product.** The business owner explicitly named speed as the #1 quality attribute. A feature that makes a screen slower is a regression even if it "adds value". N+1 queries and unpaginated fetches are bugs, not tech debt.
2. **Debt tracking is the killer feature.** Small Egyptian merchants run on informal credit ("اكتبها عليّ"). The ledger — who owes what, precisely, with history — is the reason this product exists. It must never be wrong. A wrong balance destroys trust permanently.
3. **Bilingual reality.** Users operate in Arabic; the code is English. User-facing validation messages may be Arabic (see `InventoryService::checkStock`). Products carry `description_ar` / `description_en`.
4. **Flexibility over enforcement.** Real shops need escape hatches: manual price overrides on order items, `manual_total` on orders, custom `order_date` for backdated entries, walk-in customers, nullable warehouses (not all sales touch tracked stock). The system records what happened; it does not force an idealized workflow.

## Engineering philosophy

1. **One source of truth for money: `LedgerService`.** This rule exists because a real production bug shipped when three controllers each reimplemented "balance = total − payments" and disagreed. See [ADR-003](../01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md).
2. **Derive, don't store — except when performance says otherwise, and then sync in one place.** Order *status* is always derived from payments. Order *total* started derived and is now stored for performance, with all writes funneled through `LedgerService::adjustOrderCharge` so the stored total and the ledger charge can never diverge. See [ADR-004](../01-architecture/decisions/ADR-004-stored-order-total.md).
3. **Ship, then harden.** MVP speed wins ties. But three things are never traded away: tenant isolation, ledger correctness, transactional integrity (money + stock moves happen inside `DB::transaction`).
4. **Thin controllers, fat services, dumb models.** Business logic lives in `app/Services/`. Controllers validate (via FormRequests), delegate, and shape responses (via Resources). See [backend-architecture.md](../01-architecture/backend-architecture.md).
5. **Documentation follows incidents.** Rules in these docs exist because something went wrong or almost did. When adding a rule, state the incident or risk that justifies it — rules without a "why" get ignored.

## Anti-goals (deliberately not doing)

- **Not** building a general accounting package (no chart of accounts, no double-entry journal in the accounting sense — the ledger is a customer/supplier balance ledger).
- **Not** supporting enterprise workflows (approvals chains, audit sign-off) until a paying customer needs them.
- **Not** abstracting for hypothetical future tenants ("what if a tenant wants X") — build for the current tenant, keep `tenant_id` discipline so future tenants are possible.

---

**Related documents**: [Backend Architecture](../01-architecture/backend-architecture.md), [Ledger](../06-domain/ledger.md), [Roadmap](../10-roadmap/roadmap.md).
**Future improvements**: write a one-page "pitch" version when the product goes multi-customer.
**Open questions**: pricing model for the SaaS (per store? per user?) — undecided, irrelevant until second tenant.
**Last review checklist**: [ ] philosophy still matches how decisions are actually made, [ ] anti-goals still true. Last reviewed: 2026-07-08.
