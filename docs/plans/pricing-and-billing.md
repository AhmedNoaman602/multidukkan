# Implementation Plan — Pricing & Billing (SaaS Subscriptions)

**Status: not started. Do not implement without approval, and do not start until the open decisions in [`../architecture/pricing-and-billing.md`](../architecture/pricing-and-billing.md) §11 are answered.** Executable by another AI/engineer. Read [`../architecture/pricing-and-billing.md`](../architecture/pricing-and-billing.md) and [`../architecture/saas-platform.md`](../architecture/saas-platform.md) fully first.

> ⚠️ **The prime directive:** this is Skaibeam-charges-the-business billing. It must never read from or write to MultiDukkan's internal ledger (`LedgerService`, `ledger_entries`, `payments`, `orders`). Any PR that couples the two is rejected. See architecture doc §1 ("three ledgers").

---

## Objective

Add a reusable, provider-agnostic **subscription billing** capability to the platform so Skaibeam can charge businesses to use MultiDukkan (monthly/yearly, trials, upgrades/downgrades, cancellation, grace, failed-payment handling), gate product features by entitlements, and keep it cleanly separable for future products — **without** touching the merchant's own accounting.

## Current state

- `tenants` table is `id/name/timestamps` only — no billing, plan, or status.
- Signup (`AuthController::register`) creates tenant + admin + walk-in customer in a transaction; **no billing**.
- No `accounts` layer, no plans, no payment provider, no entitlement enforcement. All features are ungated.
- Money discipline already established (integer-safe ledger, `DB::transaction`, one source of truth) — mirror it here in a *separate* module.

## Desired outcome

- A namespaced `Platform\Billing` module (extractable later) implementing: products/plans/prices, subscriptions with a status machine, entitlements + usage limits, one payment-provider adapter (Paymob recommended), webhooks (idempotent, signature-verified), invoices/receipts, billing history, and a Billing UI in the app.
- Feature gating enforced server-side at MultiDukkan creation points, failing safe with upgrade-oriented responses.
- EGP-only at launch, schema ready for multi-currency.
- Zero coupling to `LedgerService`.

## Files likely to be created

**Backend (`multidukkan` API), namespaced under `app/Platform/Billing/`:**
- Models: `Account`, `BillingProduct`, `Plan`, `PlanPrice`, `PlanEntitlement`, `Subscription`, `UsageCounter`, `BillingInvoice`, `BillingEvent`, `PaymentMethod`
- Services: `SubscriptionService`, `EntitlementService`, `BillingInvoiceService`, `DunningService`
- Gateway: `Contracts/PaymentGateway.php`, `Gateways/PaymobGateway.php` (+ later `FawryGateway`, `StripeGateway`)
- Controllers: `SubscriptionController`, `BillingWebhookController`, `PlanController` (public plan listing), `BillingPortalController` (invoices/history)
- FormRequests: `StartSubscriptionRequest`, `ChangePlanRequest`, `CancelSubscriptionRequest`
- Middleware: `EnsureEntitled` (feature/limit gate)
- Jobs: `ChargeRenewalsJob` (scheduled), `RetryFailedPaymentJob`, `ReconcileSubscriptionsJob`
- Policies: `SubscriptionPolicy` (tenant/account isolation)
- Migrations: one per new table (see DB changes)
- Config: `config/billing.php` (plans→entitlements map, grace lengths, provider config, currencies)
- Tests: feature tests per flow (see Testing)

**Frontend (`multidukkan-frontend`):**
- `src/pages/Billing.jsx` (plan, status, payment method, invoices, upgrade/cancel)
- `src/pages/Onboarding` update (plan selection at signup) — extend existing onboarding
- `src/components/billing/*` (PlanCard, UpgradeModal, InvoiceList, PaymentMethodForm — provider hosted fields)
- API calls via existing `src/api/axios.js` pattern

**Marketing (`skaibeam-web`):** wire the static Pricing pages to real plan data (or keep static + linked).

## Files likely to be modified

- `AuthController::register` (or a new `SignupService`) — create `Account` + `Subscription{trialing}` alongside tenant, in the same transaction. **Do not** entangle with tenant business setup beyond the account link.
- `routes/api.php` — add billing routes (see Routes).
- `config/cors.php` / `SANCTUM_STATEFUL_DOMAINS` — allow the billing webhook path (public) and app origin.
- `/me` endpoint (`AuthController::me`) — include subscription status + entitlements for UI gating.
- MultiDukkan controllers at gated creation points (e.g. `StoreController`, `WarehouseController`, `UserController`, `ProductController`, AI endpoints) — apply `EnsureEntitled` middleware/checks. **Additive only** — no business-logic change.
- `bootstrap/app.php` — register billing middleware + scheduled jobs; `bootstrap/providers.php` if a `BillingServiceProvider` is added.
- `docs/architecture/pricing-and-billing.md` — downgrade to Tier 1 as built; add provider ADR.

## Database changes

New tables (all integer minor-unit money, `account_id` FK to `accounts`): `accounts`, `billing_products`, `plans`, `plan_prices`, `plan_entitlements`, `subscriptions`, `usage_counters`, `billing_invoices`, `billing_events` (unique `provider_event_ref` for idempotency), `payment_methods`. Add nullable `account_id` to `tenants` (the platform seam). Exact columns in architecture doc §3.

**Constraints:** money as `unsignedBigInteger amount_minor` + `currency`; unique `(provider_event_ref)`; indexes on `subscriptions(account_id, status)`, `usage_counters(subscription_id, metric, period_start)`. InnoDB (see SECURITY-AUDIT M-04 — set engine explicitly). **No FK to any MultiDukkan business table except `tenants.account_id`.**

## Routes

```
# public (no session; signature-verified)
POST /api/v1/billing/webhooks/{provider}      → BillingWebhookController

# public listing (for pricing pages)
GET  /api/v1/plans                             → PlanController@index

# authenticated (account-scoped)
GET   /api/v1/billing/subscription             → current subscription + entitlements
POST  /api/v1/billing/subscription             → start/checkout (StartSubscriptionRequest)
PATCH /api/v1/billing/subscription             → upgrade/downgrade (ChangePlanRequest)
DELETE/api/v1/billing/subscription             → cancel (CancelSubscriptionRequest)
GET   /api/v1/billing/invoices                 → billing history
GET   /api/v1/billing/invoices/{id}/pdf        → receipt PDF
POST  /api/v1/billing/payment-methods          → attach tokenized card (provider hosted)
```

## Components

Backend: `SubscriptionService` (state machine), `EntitlementService` (resolve plan→entitlements, cached per request), `PaymentGateway` adapter, `BillingWebhookController` (idempotent), `DunningService`, scheduled jobs. Frontend: `Billing.jsx` + `components/billing/*`, plan selection in onboarding.

## Backend changes

- Implement the status machine in `SubscriptionService` (architecture §3.1): `trialing → active → past_due → (active|expired)`, `active → canceled`, etc. All transitions in `DB::transaction`, mirroring the app's money discipline.
- `EntitlementService` reads the static `config/billing.php` plan→entitlement map; exposes `allows(feature)` / `limit(key)` / `withinLimit(metric)`.
- `EnsureEntitled` middleware returns a clean **402 Payment Required** / **403** with an upgrade hint — never a 500.
- Webhooks are the **only** writer of paid state; verify signature, store event (idempotent), transition subscription, issue invoice.
- Renewal/dunning/reconciliation as scheduled jobs with idempotency keys.

## Frontend changes

- Billing page + onboarding plan selection, using the existing axios/token pattern and `useToast`. Payment card entry uses the **provider's hosted fields/redirect** — the SPA never handles PAN. Gate UI from `/me` entitlements (server remains source of truth).

## Dependencies

- **Backend:** payment provider SDK (Paymob HTTP integration — may be plain HTTP client, no heavy SDK), a PDF generator for receipts (e.g. `barryvdh/laravel-dompdf`). Avoid a heavyweight billing package (e.g. Cashier is Stripe-centric and Stripe is likely unusable in Egypt — see architecture §4). Confirm before adding.
- **Frontend:** provider's hosted-fields JS (loaded only on the Billing page). No card libraries of our own.
- **Decision dependency:** provider choice (Paymob recommended) must be confirmed first.

## Implementation phases

> Do not start Phase 1 until architecture §11 decisions are made (provider, pricing shape, trial model, grace model).

1. **Account seam + plans (no charging yet)** — `accounts` + `tenants.account_id`; `billing_products`/`plans`/`plan_prices`/`plan_entitlements`; seed MultiDukkan plans (EGP); `GET /plans`; wire signup to create `Account` + `Subscription{trialing}`. No payment provider yet — everyone is on trial.
2. **Entitlements + gating** — `EntitlementService`, `EnsureEntitled`, config map; apply gates at real limits (stores/users/products/AI); extend `/me`; UI gating. Fail-safe. (This alone delivers value: trials + limits, no money yet.)
3. **Payment provider + paid subscriptions** — `PaymentGateway` interface + `PaymobGateway`; checkout/tokenization; trial→active; renewals job; `billing_invoices` + receipts; Billing UI.
4. **Lifecycle robustness** — webhooks (idempotent, signed), dunning/grace, past_due handling, upgrade/downgrade, cancellation, reconciliation job, billing history UI.
5. **Hardening + launch** — tax/VAT on invoices, security review of the whole module, reconciliation monitoring, Arabic billing emails, prod provider keys, go-live checklist.

## Acceptance criteria

- A new signup lands on a **trial** with correct entitlements; trial expiry revokes access as designed.
- Gated actions (e.g. creating an N+1th store beyond plan limit) are blocked server-side with a clean upgrade response; UI reflects it.
- A card can be added (via provider hosted fields — PAN never hits our servers) and a subscription goes `trialing → active` with a stored `billing_invoice`.
- Renewal charges run on schedule and are **idempotent** (re-run charges once).
- Failed renewal → `past_due` → grace behavior as decided → recovery or `expired`.
- Upgrade/downgrade/cancel behave per the decided policies; downgrade respects limit fit.
- Webhooks are signature-verified and idempotent (re-delivery is a no-op via unique `provider_event_ref`).
- Billing history + downloadable receipts available; receipts are visually distinct from MultiDukkan's own sales invoices.
- **Zero references** between billing code and `LedgerService`/`ledger_entries`/`orders`/`payments` (grep-verified in review).
- Money stored/handled as integer minor units; EGP works; schema accepts a second currency without migration to code paths.

## Testing requirements

- Feature tests (PHPUnit, `RefreshDatabase`, factories — per project test rules) for: trial creation at signup; each status transition; entitlement allow/deny at every gate; usage-limit enforcement; upgrade/downgrade; cancel + period-end; grace + dunning + expiry.
- **Webhook tests:** valid-signature processing, invalid-signature rejection, idempotent re-delivery (no double state change), out-of-order events.
- **Idempotency tests:** renewal job run twice charges once; concurrent webhook + job don't double-apply (use `lockForUpdate` — echoes SECURITY-AUDIT M-01/M-05 lessons).
- **Isolation tests:** an account/tenant cannot read or mutate another's subscription/entitlements; a tenant cannot set its own plan/entitlements via the API.
- **Firewall test:** an automated test (or CI grep) asserting the billing module imports nothing from `App\Services\LedgerService` / domain money models, and vice versa.
- **No-card-data test:** assert no PAN/CVV fields exist on any request/model; only provider tokens stored.
- Frontend: billing page happy paths; gated-UI rendering from entitlements; provider hosted-fields load only on billing page.
- Negative tests verify DB state (per project rules).

## Security considerations

- **Webhook endpoint** = high-value surface: public but signature-verified, idempotent, rate-limited, never trusts body-claimed amounts/status without provider verification; all events logged to `billing_events`. Treat with ledger-level rigor.
- **Entitlements/subscription are privileged state** — only webhooks/server logic write paid status; tenants can never self-grant a plan/entitlement. Enforce with policies + the (recommended) global tenant scope (SECURITY-AUDIT H-02).
- **No card data ever** — provider hosted fields/tokenization only; PCI scope stays minimal.
- **Isolation:** every billing query scoped by `account_id`; add tests. Don't let billing become a cross-tenant hole.
- **Concurrency:** use `lockForUpdate` on subscription rows in charge/renewal/webhook paths (the project already learned this from the refund/reversal races — SECURITY-AUDIT M-01/M-05).
- **Provider secrets** in env only; never in client bundle or repo; rotate on leak.
- **Separation from business ledger** is itself a security property: a billing bug must be incapable of corrupting merchant financial data.
- Fix/align with SECURITY-AUDIT before/with this work: set MySQL engine to InnoDB explicitly (M-04) since billing depends on transactions; ensure Telescope isn't logging billing/webhook payloads with secrets in prod (H-01).

## What should explicitly NOT be implemented yet

- **Nothing until architecture §11 decisions are made** (provider, pricing shape, trial/grace/proration policies).
- **No second payment provider** — one adapter implementation (Paymob) at launch; interface only for the rest.
- **No usage-based/metered billing, seats, proration engine, coupons/promos, plan-builder UI, referral/affiliate, or self-serve enterprise** — all deferred.
- **No multi-currency code paths** — EGP only; schema supports more, code doesn't branch yet.
- **No cross-product platform extraction** — keep it namespaced in this repo; extract only when product #2 is real.
- **No SSO / identity server** — one product, one login.
- **Absolutely no coupling to MultiDukkan's internal ledger** — not now, not ever.
- **No Stripe/Cashier assumption** — likely unusable for Egyptian EGP acquiring; revisit only for international.

---

**Related documents:** [`../architecture/pricing-and-billing.md`](../architecture/pricing-and-billing.md) (the design + open decisions §11), [`../architecture/saas-platform.md`](../architecture/saas-platform.md) (account seam, entitlement mechanism), [`../06-domain/ledger.md`](../06-domain/ledger.md) + [`../01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md`](../01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md) (the ledger this must never touch), `docs/security/SECURITY-AUDIT.md` (M-01/M-04/M-05/H-01/H-02), [`../04-engineering-standards/testing-strategy.md`](../04-engineering-standards/testing-strategy.md).

**Future improvements:** promote to Tier 1 as built; provider ADR; international/Merchant-of-Record phase; metering if a plan ever needs it.

**Open questions:** all of architecture §11 (blocking). Plus: PDF generator choice; whether Phase 1–2 (trials + gating, no money) ship ahead of payment integration (recommended — delivers value early).

**Last review checklist:** [ ] §11 decisions recorded before build, [ ] billing↔ledger firewall intact, [ ] one provider only, [ ] no card data, [ ] EGP-only code paths. Last reviewed: 2026-07-19.
