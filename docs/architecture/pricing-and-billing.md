# Pricing & Billing Architecture (Skaibeam SaaS)

**Status: Tier 2 (designed, not built).** This is the SaaS subscription billing that Skaibeam charges the **business** for using MultiDukkan (and future products). It is **completely separate** from the money features *inside* MultiDukkan (orders, payments, the customer/supplier ledger). Keeping those apart is the central design rule of this document.

Companion plan (actionable): [`../plans/pricing-and-billing.md`](../plans/pricing-and-billing.md). Platform framing: [`./saas-platform.md`](./saas-platform.md).

---

## 1. The one rule: three ledgers, never mixed

There are **three** independent money systems in play. They must not share tables, services, or code paths:

| # | System | Who owes whom | Where it lives | Source of truth |
|---|---|---|---|---|
| 1 | **SaaS subscription billing** | The business owes **Skaibeam** for the product | New `Platform\Billing` module (this doc) | `subscriptions` + provider |
| 2 | **Payment provider records** | The provider's view of charges/refunds | External (Paymob/Stripe/etc.) | The provider |
| 3 | **MultiDukkan internal ledger** | The business's **end-customers/suppliers** owe the **business** | Existing `LedgerService` / `ledger_entries` (`06-domain/ledger.md`, ADR-003) | `LedgerService` |

**System 3 already exists and is sacred** (ADR-003: one source of truth; the ledger must never be wrong). This document adds System 1 and integrates System 2. **Under no circumstances** do subscription charges post to `ledger_entries`, and **no** MultiDukkan business money touches the subscription tables. A merchant's overdue *shopper* debt has nothing to do with whether the merchant paid *Skaibeam* this month, and vice versa.

Why this rule is load-bearing: if System 1 ever writes into System 3, a subscription refund could corrupt a merchant's customer balances — destroying the trust that is the product's entire value proposition. This separation is the billing equivalent of the `tenant_id` discipline.

---

## 2. Layered architecture

```
┌────────────────────────────────────────────────────────────┐
│  A. Billing & subscription state  (our database, our truth) │
│     products · plans · prices · subscriptions · entitlements │
│     invoices(receipts) · billing history · usage counters    │
└───────────────┬────────────────────────────────────────────┘
                │ intents / status sync (webhooks + API)
┌───────────────▼────────────────────────────────────────────┐
│  B. Payment provider integration  (adapter, swappable)      │
│     charge · tokenize card · recurring · refund · webhook    │
│     ── Provider (Paymob / Stripe / Fawry / …) ──             │
└─────────────────────────────────────────────────────────────┘

        (completely separate — no arrows to/from below)

┌─────────────────────────────────────────────────────────────┐
│  C. MultiDukkan internal business ledger  (EXISTING)         │
│     LedgerService · ledger_entries · payments · refunds      │
│     the merchant's OWN accounting — untouched by A and B     │
└─────────────────────────────────────────────────────────────┘
```

- **Layer A** owns *what the customer is entitled to and what they owe Skaibeam*. It is provider-agnostic. It is the truth for access control (entitlements).
- **Layer B** is a thin **adapter** around one payment provider, isolated behind an interface so the provider can be swapped. It never makes product decisions.
- **Layer C** is the existing MultiDukkan domain. This doc only references it to say: **do not touch it.**

---

## 3. Layer A — billing & subscription domain model

Provider-agnostic. Modeled so multiple products (Skaibeam platform) can reuse it (see saas-platform doc). Tables (designed; names indicative):

| Table | Purpose | Key fields |
|---|---|---|
| `billing_products` | A sellable Skaibeam product | `id`, `slug` (`multidukkan`), `name`, `active` |
| `plans` | A plan within a product | `id`, `billing_product_id`, `slug` (`starter`/`pro`/`business`), `name`, `active`, `trial_days`, `is_public` |
| `plan_prices` | A price for a plan (per interval/currency) | `id`, `plan_id`, `interval` (`monthly`/`yearly`), `currency` (`EGP`/`USD`), `amount_minor` (integer, piastres/cents), `provider_price_ref` (nullable) |
| `plan_entitlements` | Feature/limit config per plan | `id`, `plan_id`, `key` (`max_stores`/`ai_enabled`/…), `value` (int/bool/json) |
| `subscriptions` | An account's subscription to a product | `id`, `account_id`, `billing_product_id`, `plan_id`, `plan_price_id`, `status`, `trial_ends_at`, `current_period_start`, `current_period_end`, `cancel_at`, `canceled_at`, `grace_ends_at`, `provider_subscription_ref`, `provider_customer_ref` |
| `subscription_items` *(optional/future)* | For per-seat/metered add-ons | defer unless needed |
| `usage_counters` | Metered usage per period | `id`, `account_id`, `subscription_id`, `metric` (`orders`/`stores`/`users`), `period_start`, `count` |
| `billing_invoices` | Our own receipt/invoice record (see §9) | `id`, `account_id`, `subscription_id`, `number`, `currency`, `amount_minor`, `tax_minor`, `status`, `issued_at`, `paid_at`, `provider_invoice_ref`, `pdf_path` |
| `billing_events` | Audit of provider webhooks & state changes | `id`, `account_id`, `type`, `provider_event_ref` (unique — idempotency), `payload_json`, `processed_at` |
| `payment_methods` *(if provider needs stored refs)* | Tokenized card reference (no PAN ever) | `id`, `account_id`, `provider_pm_ref`, `brand`, `last4`, `exp`, `is_default` |

Notes:
- **`account_id`** links to the platform account seam (saas-platform §4), not directly to `tenant_id`. For MultiDukkan today it's 1:1, but the FK is to `accounts`.
- **Money is stored as integer minor units** (`amount_minor`) + `currency`, never floats — same discipline as the existing ledger.
- **No card data ever stored** — only provider tokens/refs. PAN/CVV never touch our servers (PCI scope stays minimal; this is also a hard line in the security policy).

### 3.1 Subscription status machine

`status ∈ { trialing, active, past_due, canceled, expired, incomplete }`

```
              start trial
   (none) ─────────────────▶ trialing ──(trial ends, payment ok)──▶ active
                                 │                                    │
                    (trial ends, no payment)                 (renewal payment fails)
                                 ▼                                    ▼
                              expired  ◀──(grace ends)────────────  past_due
   active ──(user cancels)──▶ active (cancel_at set) ──(period end)──▶ canceled
   past_due ──(payment recovered)──▶ active
```

- **`trialing`**: full access, no payment yet.
- **`active`**: paid, entitlements granted.
- **`past_due`**: renewal failed; enters **grace period** (`grace_ends_at`) with access retained (or read-only — decision, §6).
- **`canceled`**: user canceled; access until `current_period_end`, then no renewal.
- **`expired`**: trial or grace ended without payment; access revoked to free/none.
- **`incomplete`**: initial payment not completed (checkout abandoned).

**Entitlements derive from status + plan.** Access = `status ∈ {trialing, active}` OR (`past_due` AND within grace). Everything else = revoked/limited. The server computes this; it is the gate for Layer A's entitlement checks (saas-platform §6).

---

## 4. Layer B — payment provider: the decision

**Do not assume a provider.** The tradeoffs, Egypt-first:

| Provider | Egypt fit | Recurring/subscriptions | Local methods (cards, wallets, Fawry, InstaPay) | Integration effort | Notes / risks |
|---|---|---|---|---|---|
| **Paymob** | ★ Egypt-native, widely used | Card tokenization + recurring supported; you orchestrate the subscription logic | ✅ Cards, mobile wallets, Fawry, kiosk, some BNPL | Medium — good docs, EGP-native | **Recommended primary for Egypt.** You own the subscription state machine; provider handles charges. Webhooks (HMAC) available. |
| **Fawry** | ★ Egypt-native, cash/kiosk reach | Recurring limited; strongest for cash/reference payments | ✅ Especially cash/kiosk, reference codes | Medium | Great for *unbanked* customers paying cash at kiosks; weaker for automated card recurring. Possible secondary method. |
| **Stripe** | ✗ Not available to Egyptian entities for local acquiring (as of writing) | ★ Best-in-class Billing (plans, proration, dunning, invoices) | Limited local methods; EGP support constrained | Low (if usable) | Ideal *technically* but **entity/availability is the blocker** — likely unusable for an Egyptian company charging EGP. Revisit for international expansion. |
| **Paddle / LemonSqueezy (Merchant of Record)** | Handles global tax/VAT as MoR | ★ Subscriptions built-in | Global cards, less local-Egypt | Low | MoR removes tax/compliance burden — attractive for **international** SaaS later; weaker for Egyptian local methods now. |

**Recommendation:**
1. **Launch on Paymob** (Egypt-native, EGP, local methods, supports the card-tokenized recurring model). Optionally add **Fawry** as a cash/reference method for merchants without cards.
2. **Keep the provider behind an adapter interface** (`PaymentGateway`) so Stripe/Paddle can be added for **international** without touching Layer A.
3. **Revisit a Merchant-of-Record (Paddle/LemonSqueezy) when going international**, to offload global VAT/tax.

Because most Egypt-friendly providers give you *charging* but not a full *subscription engine* (unlike Stripe Billing), **the subscription state machine lives in Layer A (our code), not the provider.** This is more work than "let Stripe do it," but it's the realistic Egypt path and it makes the provider swappable.

### 4.1 The adapter interface (provider-agnostic seam)

```
interface PaymentGateway {
  createCustomer(account): providerCustomerRef
  tokenizeCard(...): providerPmRef           // hosted fields / redirect — never see PAN
  charge(account, amount_minor, currency, pmRef, idempotencyKey): ChargeResult
  refund(providerChargeRef, amount_minor, idempotencyKey): RefundResult
  verifyWebhook(request): WebhookEvent | throw
}
```

MultiDukkan's Layer A calls only this interface. `PaymobGateway`, `FawryGateway`, `StripeGateway` implement it. Switching providers = new class + config, no schema change.

---

## 5. Billing flows

### 5.1 Signup → trial → paid
1. Marketing CTA → signup (creates `account` + `tenant` + `subscription{status: trialing, trial_ends_at}`), **no card required** (recommended: reduce friction) or card-upfront (reduces trial abuse) — decision, §11.
2. Access granted immediately (entitlements from the trial's plan).
3. Before `trial_ends_at`, prompt to add payment. On success → first charge → `status: active`, set `current_period_*`.
4. On trial end without payment → `status: expired`, entitlements revoked to free/none.

### 5.2 Recurring renewal
- A scheduled job (daily) finds subscriptions with `current_period_end` near/now and `status: active`, calls `gateway.charge(...)` with an **idempotency key** = `subscription_id:period_end`.
- Success → advance `current_period_*`, issue `billing_invoice`, keep `active`.
- Failure → `status: past_due`, set `grace_ends_at`, start **dunning** (retry schedule + notifications).

### 5.3 Upgrade / downgrade
- **Upgrade** (Starter→Pro): change plan immediately; **proration** decision (§11) — simplest launch: charge the new plan from next period, grant entitlements now (generous, simple). Full proration is a later refinement.
- **Downgrade**: apply at **period end** (avoid mid-period refunds); enforce that current usage fits the lower plan's limits before allowing (or schedule + warn).

### 5.4 Cancellation & grace
- User cancels → `cancel_at = current_period_end`, `status` stays `active` until then, then `canceled`. No mid-period refund by default (§11).
- Failed payment → `past_due` → grace window (e.g. 7–14 days) with access (or read-only) → recover (back to `active`) or `expired`.

### 5.5 Refunds (rare, manual)
- Subscription refunds go through `gateway.refund(...)` and update `billing_invoices`/`billing_events` **only**. They **never** touch `ledger_entries`. A refund of a subscription charge is a Layer A/B event exclusively.

---

## 6. Grace periods & failed-payment policy

- **Grace length:** configurable (default 7 days for monthly, longer for yearly). Stored on the subscription (`grace_ends_at`).
- **During grace:** recommended **read-only access** (they can see their data, export, and pay — but not create new orders) rather than full lockout — a merchant locked out of their own debt records mid-day is a support disaster and a trust hit. Decision in §11.
- **Dunning:** retry on a schedule (e.g. day 1, 3, 7), each with an Arabic notification (email/SMS/WhatsApp later). After final retry → `expired`.
- **Never delete data on non-payment.** Retain per a documented retention policy; export must remain available. (Aligns with the marketing FAQ "what happens to my data if I stop paying".)

---

## 7. Webhooks (the only writer of paid state)

- Provider webhooks are the **authoritative signal** that money moved. The API exposes `POST /api/v1/billing/webhooks/{provider}` — **public, no session auth**, but **signature-verified** (`gateway.verifyWebhook`).
- **Idempotency:** every event stored in `billing_events` keyed by `provider_event_ref` (unique). Re-delivered events are no-ops.
- **Reconciliation:** a scheduled job reconciles local subscription state against the provider periodically, because webhooks can be missed.
- **Security:** reject unsigned/invalid events; never trust the request body's claimed amount/status without verifying against the provider; rate-limit; log to `billing_events`. This endpoint is high-value attack surface — treat it like the ledger.

---

## 8. Currency & internationalization

- **Launch: EGP only.** `plan_prices` carry `currency` + `amount_minor` (piastres) from day one, so multi-currency is a data addition, not a refactor.
- **Display:** Arabic-first pricing pages, EGP formatting.
- **International later:** add USD (and others) `plan_prices` rows; consider a Merchant-of-Record provider for tax/VAT; add currency selection on pricing pages. **Do not build FX conversion** — set explicit per-currency prices.
- **VAT/tax:** Egyptian VAT handling on invoices is a real requirement before charging — capture tax as `tax_minor` on `billing_invoices`. Confirm the legal/tax setup before go-live (out of scope for code, flagged as a launch gate).

---

## 9. Invoices / receipts

- We issue our **own** receipt/invoice record (`billing_invoices`) for each successful charge — independent of, and reconciled against, the provider's. Sequential `number` per account/company, PDF generated and stored (`pdf_path`), downloadable in a **Billing** area of the app.
- These are **Skaibeam→business** invoices. They must be visually and structurally distinct from MultiDukkan's *own* customer invoices (which the business issues to *its* shoppers) so no one confuses "my SaaS bill" with "my shop's sales invoice." Different templates, different module, different wording.
- Billing history = the list of `billing_invoices` + `billing_events` for the account.

---

## 10. Where this plugs into the existing app

- **New module:** `app/Platform/Billing/` (or a package) — models, `SubscriptionService`, `EntitlementService`, `PaymentGateway` adapters, webhook controller, scheduled jobs. Namespaced so it can be extracted for the platform later (saas-platform §3).
- **Entitlement enforcement:** a middleware/gate checked at feature entry points in MultiDukkan controllers (mirrors `tenant_id` checks). Fail-safe, returns upgrade-oriented 402/403.
- **`/me` (or `/entitlements`):** expose plan + entitlements + subscription status so the SPA can gate UI (server remains source of truth).
- **Frontend:** a **Billing** section in the app (plan, status, payment method, invoices, upgrade/cancel) — distinct from anything in the merchant's business screens. Marketing **Pricing** pages (public) link into signup.
- **Untouched:** `LedgerService`, `ledger_entries`, `payments`, `OrderService`, everything in `06-domain/`. Verify in review that no billing code imports them and vice versa.

---

## 11. Open decisions (need product input before build)

1. **Card-upfront trial vs no-card trial** (friction vs trial abuse).
2. **Grace-period access model**: read-only vs full lock vs full access.
3. **Proration** on upgrades: none (simple) vs prorated (fair).
4. **Mid-period cancellation refunds**: none (standard SaaS) vs prorated.
5. **Pricing shape**: per-tenant flat, per-store, per-user, or usage-tiered? (The philosophy doc's open question — "per store? per user?" — is still open. Recommend **flat plan tiers with usage *limits*** (stores/users/orders) rather than pure per-seat, to keep billing predictable for small merchants.)
6. **Provider**: confirm Paymob primary (+ Fawry?) — depends on the company's payment account eligibility.
7. **Tax/VAT**: legal setup for issuing compliant Egyptian invoices.

---

## 12. Anti-overengineering guardrails

- Build for **one product, one currency (EGP), a handful of plans**. The multi-product/multi-currency shape is in the *schema* (cheap) but not in the *code paths* yet.
- **Do not** build metered/usage billing, seat management, proration engines, coupons/promos, dunning-email sequences, or a self-serve plan-builder for launch. Static plans + monthly/yearly + trial + cancel + grace is the MVP.
- **Do not** build a provider abstraction with three implementations on day one — ship the `PaymentGateway` interface + **one** implementation (Paymob). The interface is the future-proofing; extra implementations are not.
- **Do not** store any card data. Ever.
- The subscription engine is the minimum needed to gate access correctly and charge reliably — nothing more until a real plan-difference or a second product demands it.

---

**Related documents:** [`./saas-platform.md`](./saas-platform.md) (account seam, entitlement mechanism), [`./public-websites.md`](./public-websites.md) (Pricing pages that sell these plans), [`../06-domain/ledger.md`](../06-domain/ledger.md) + [`../01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md`](../01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md) (System 3 — the ledger this must never touch), [`../plans/pricing-and-billing.md`](../plans/pricing-and-billing.md), `docs/security/SECURITY-AUDIT.md` (webhook/entitlement security rigor).

**Future improvements:** promote to Tier 1 once built; add an ADR recording the final provider choice and the pricing shape; add international/MoR section when expansion is real.

**Open questions:** all of §11. Nothing here should be built until §11.1–6 are answered by the business owner.

**Last review checklist:** [ ] three-ledger separation still absolute (no billing↔`ledger_entries` coupling), [ ] money still integer minor units, [ ] no card data stored, [ ] provider still behind adapter. Last reviewed: 2026-07-19 (design only).
