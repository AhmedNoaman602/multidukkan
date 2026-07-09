# AI Collaboration Guide

**If you are an AI session (Claude Sonnet, Claude Opus, ChatGPT, anything else) about to modify this codebase: this document is your contract.** It exists because AI sessions are powerful, fast, and have no memory of last month's production bug. The humans do. This file is their memory, made executable.

## Before writing any code — the decision process

1. **Is money involved?** (balances, payments, totals, credit, refunds, costs) → Read [ledger.md](../06-domain/ledger.md) and [financial-calculations.md](../07-business-rules/financial-calculations.md) first. No exceptions.
2. **Does your plan contradict an ADR?** Check [the ADR index](../01-architecture/decisions/README.md). If yes: stop, name the ADR to the user, and ask. Never implement around a locked decision.
3. **Does the entity you're touching have a domain doc?** Read it. It documents quirks (dual ledger schemas, unit conversion sites, snapshot semantics) that the code alone won't teach you in time.
4. **Is the "bug" you found actually a documented decision?** Check the divergence table below before fixing anything that looks wrong.

## Architectural rules you must never violate

1. **No financial math outside `LedgerService`.** Need a balance/debt/credit number? Call `getBalance` / `getBalancesForCustomers` / `getSupplierBalance` / `getBalancesForSuppliers` / `getCreditBalance`. Missing method → add it to `LedgerService`, never inline. ([ADR-003](../01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md) — born from a real production bug.)
2. **Never write `orders.total` directly** after creation — only via `LedgerService::adjustOrderCharge`. Never store an order *status*. ([ADR-004](../01-architecture/decisions/ADR-004-stored-order-total.md))
3. **Never `update()`/`delete()` a `LedgerEntry`** outside the two sanctioned methods (`adjustPayment`, `adjustOrderCharge`). Corrections append (`REVERSAL`, `REFUND`, `CREDIT_APPLY`). ([ADR-006](../01-architecture/decisions/ADR-006-ledger-mutability-boundaries.md))
4. **Never mutate `inventory.quantity`** outside `InventoryService`; every mutation logs an `inventory_transactions` row. Base units only.
5. **Every query on a business table scopes `tenant_id` explicitly.** There is no global scope saving you. Foreign IDs in input → `BelongsToTenant` rule; loads in services → re-verify and fail loudly.
6. **`DB::transaction` around anything touching ≥2 of** {orders, payments, ledger_entries, inventory}.
7. **Controllers thin, services fat**: validation in FormRequests (`$request->validated()` only), business logic in `app/Services/`, output through API Resources, type-hinted service signatures.
8. **Snapshots are sacred**: invoices render from `product_name`/`unit_price`/`customer_name_snapshot` on the line items — never re-join live product/customer data for historical display.

## Documented divergences — things that look like bugs but are decisions

| What you'll see | Why it's intentional | Do NOT |
|---|---|---|
| `orders.total` stored despite old "never store total" comments/docs | Performance + `manual_total` ([ADR-004](../01-architecture/decisions/ADR-004-stored-order-total.md)) | Revert to derived totals |
| Ledger entries edited in `adjustPayment`/`adjustOrderCharge` | Statement readability for typo-corrections ([ADR-006](../01-architecture/decisions/ADR-006-ledger-mutability-boundaries.md)) | Convert to reversal-append without a superseding ADR |
| Credit `Payment` rows hard-deleted on order cancel | They're bookkeeping artifacts; their ledger entries remain | "Fix" to soft delete |
| PO stock receipts logged as `RETURN` type | Known quirk, disambiguated by `reference_type` | Rename types in place (breaks history) — needs a migration decision |
| Two ledger schemas (customer `type`-based, supplier `direction`-based) in one table | Generational; unification is a planned migration, not a drive-by | Mix idioms or "harmonize" casually |
| Arabic validation messages | Users are Arabic-speaking merchants | Translate to English |
| `manual_total`, price overrides, backdated `order_date` | Merchant escape hatches — flexibility is a feature | Add validation that blocks them |

## Known open defects (fix only when asked; don't be surprised by them)

- `PaymentService::processDirectPayment`: `throw new \ValidationException(...)` — nonexistent class, fatals on the already-paid path.
- Invoice numbers collide after 999/year (substr(-3) parsing) — both `OrderService` and `PurchaseOrderService`.
- `adjustItem` not transaction-wrapped; `adjustPayment` computes but never uses `$otherPaymentsTotal`.
- Stale `BUG:` comment in `PurchaseOrderService` describing already-fixed cost averaging.

## How to modify code here

- **Match existing style**, including comment density. Don't add doc-blocks to files that don't use them; don't strip the narrative comments from services that do.
- **Small diffs.** Refactor only what the task requires. "While I was here" changes to money code are how regressions happen without test coverage noticing.
- **Refactoring money/stock code**: write/extend the feature test first (see [testing-strategy.md](../04-engineering-standards/testing-strategy.md) — balance assertions go through `LedgerService`, negative tests assert DB state).
- **New endpoint checklist**: route in `api.php` (+`/api` prefix in tests) → FormRequest with tenant rules → service method (typed, `User` param, transaction if needed) → Resource → feature test incl. tenant-isolation negative.
- **Before declaring any change done**: run the [code-review checklist](../04-engineering-standards/code-review-checklist.md) against your own diff and state the result. Any "No" you cannot fix goes to the user, not into the merge.
- **Performance is a requirement**: batch queries (`whereIn` + `keyBy`, `getBalancesFor*`), paginate every list, no queries in loops, no unpaginated full-table fetches. The owner named speed the #1 quality.

## Common AI mistakes observed in this project (append to this list after every incident)

1. Recomputing balances as `total − payments` in a controller or resource — the founding sin.
2. Per-row `getBalance()` in a list instead of the batch method — N+1 (shipped once, fixed in `2abffa7`).
3. "Fixing" stored `orders.total` back to derived — see divergence table.
4. Forgetting the null-warehouse branch when adding stock-touching code — throws `firstOrFail` at runtime.
5. Handling only `base` unit type — secondary lines corrupt stock counts if conversion is skipped.
6. Writing tests with hardcoded IDs or without DB-state assertions on failure paths.
7. Trusting the project CLAUDE.md status section over the code — the code is newer; these docs arbitrate.

## Escalate to the human (do not decide yourself)

- Anything requiring a new ADR or contradicting an existing one.
- Ledger schema changes, new entry types, changes to balance formulas.
- Deleting or rewriting any migration.
- Data-repair scripts against production data.

---
**Related documents**: everything — start at [docs/README.md](../README.md).
**Future improvements**: add a per-incident changelog section; wire this doc into CLAUDE.md so every session loads the pointer (done 2026-07-08).
**Open questions**: none — this doc grows by incident.
**Last review checklist**: [ ] divergence table current, [ ] defect list pruned of fixed items, [ ] mistakes list appended after incidents. Last reviewed: 2026-07-08.
