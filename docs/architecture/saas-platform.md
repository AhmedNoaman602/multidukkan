# Skaibeam SaaS Platform Architecture

**Status: Tier 2 (designed, not built).** Describes the multi-product platform direction for Skaibeam. Grounded in the current single-product reality (MultiDukkan). The guiding constraint — from the project philosophy — is **"keep `tenant_id` discipline so future products are possible, but do not abstract for hypothetical tenants."** This doc defines the seams that make future products cheap, without building any of them now.

---

## 1. The company/product model

- **Skaibeam** = the technology company (the parent brand and, eventually, the account/billing owner).
- **MultiDukkan** = the first product (a multi-tenant retail management SaaS).
- **Future products** = other SaaS apps, each with its own subdomain and its own app, but sharing identity and billing where it makes sense.

```
                         ┌─────────────────────────────┐
                         │        Skaibeam (company)    │
                         │  identity · billing · brand  │
                         └───────────────┬─────────────┘
              ┌───────────────────────────┼───────────────────────────┐
        MultiDukkan                  Future Product B             Future Product C
   (multi-tenant retail)              (some SaaS)                  (some SaaS)
   app.multidukkan.skaibeam.com       app.productb.skaibeam.com    ...
```

**Key distinction that must never blur:**
- A **Skaibeam customer** = a business that pays Skaibeam to use a product (the subject of subscription billing — see [pricing doc](./pricing-and-billing.md)).
- A **MultiDukkan tenant** = that same business's workspace inside MultiDukkan.
- A **MultiDukkan customer** = an end-customer of *that business* (a shopper who owes money — lives in the business's internal ledger).

These are three different "customers." Conflating the first and third is the single biggest modeling risk in this whole effort. The [pricing doc](./pricing-and-billing.md) exists largely to keep them apart.

---

## 2. Current reality (what exists)

- One product, one Laravel API, one React SPA (see [public-websites doc §1](./public-websites.md)).
- Multi-tenancy is **row-level**: every business-owned table has `tenant_id`; a `tenants` table holds `id` + `name` + timestamps only (`0001_01_01_000000_create_tenants_table.php`). No billing, no plan, no status, no domain on it.
- Isolation is enforced per-controller (policies check `tenant_id`, controllers re-check, FormRequests use `BelongsToTenant`). There is **no global scope** yet (flagged HIGH-02 in `docs/security/SECURITY-AUDIT.md`).
- A tenant + `tenant_admin` user + a walk-in customer are provisioned in `AuthController::register`, inside a `DB::transaction`. No billing is involved.
- Auth is Sanctum bearer tokens (`ADR-001`), roles are a string column (`ADR-002`).

**Implication:** MultiDukkan is *already* multi-tenant. The platform work is not "make it multi-tenant" — it's "add a company/identity/billing layer above the tenant, and keep future products able to plug into it."

---

## 3. Domain & routing strategy

| Host | Serves | Auth | Notes |
|---|---|---|---|
| `skaibeam.com` | Company marketing site | Public | Static/SSG |
| `multidukkan.skaibeam.com` | MultiDukkan marketing site | Public | Static/SSG; CTAs → app |
| `app.multidukkan.skaibeam.com` | The MultiDukkan React app (existing SPA) | Authenticated | Recommended split from marketing |
| `api.skaibeam.com` or `api.multidukkan.skaibeam.com` | The Laravel API | Token | Pick one; see below |
| `<product>.skaibeam.com` / `app.<product>.skaibeam.com` | Future product marketing/app | — | Same pattern |

**API host decision:** two viable shapes —
- **(a) One shared API host** (`api.skaibeam.com`) serving all products, routed by path (`/multidukkan/...`, `/productb/...`) or by module. Pro: one identity/billing surface. Con: one big app; needs strong module boundaries.
- **(b) Per-product API** (`api.multidukkan.skaibeam.com`), with a small shared identity/billing service. Pro: products stay independent and independently deployable. Con: a shared service to run.

**Recommendation for now: keep the single MultiDukkan API** (option b's degenerate case — one product, one API) and simply **carve the billing/identity code into its own namespaced module** inside it (`app/Platform/...` or a package), so that when product #2 appears you can extract it. Do not build a separate identity service for one product. This is the "reusable without overengineering" line.

**Tenant resolution:** MultiDukkan resolves the tenant from the **authenticated user's `tenant_id`**, not from the hostname. Keep it that way — do not add per-tenant subdomains (`acme.multidukkan.skaibeam.com`) unless a real requirement (white-label) appears. It's the roadmap's "not before product-market fit" item.

---

## 4. Identity & account layering (the reusable seam)

Introduce a thin **account layer** above `tenant` so that identity and billing are product-agnostic, while MultiDukkan's tenant stays the workspace. Minimum viable shape (designed, not built):

```
account            → the Skaibeam-level billing entity (the paying business)
  └─ owns → subscription(s)   → per product (see pricing doc)
  └─ owns → tenant(s)         → one per product the account uses
users              → belong to a tenant (today); gain an account link
```

For MultiDukkan today there is a 1:1:1 relationship (one account ↔ one subscription ↔ one tenant). Modeling `account` separately from `tenant` is the one piece of forward-design worth doing now, because retrofitting billing onto `tenant` directly would leak SaaS-billing concepts into the business-data schema — exactly what we must avoid. **But keep it minimal:** an `accounts` table + a nullable `account_id` on `tenants`, not a full identity platform.

> Anti-overengineering guard: do **not** build cross-product SSO, org hierarchies, team invitations across products, or a separate auth server now. One product, one login. The seam is `accounts` + `account_id`; that's all.

---

## 5. Product / platform boundary (what's shared vs product-specific)

| Concern | Shared platform (product-agnostic) | Product-specific (MultiDukkan) |
|---|---|---|
| Company brand, marketing shell | ✅ (design system) | product content |
| Identity / login | ✅ eventually | uses it |
| Subscription & billing state | ✅ (see pricing doc) | consumes entitlements |
| Payment provider integration | ✅ | — |
| Feature entitlements / plan gating | ✅ mechanism | ✅ which features |
| Usage metering | ✅ mechanism | ✅ what's metered (stores, users, orders) |
| Business domain (orders, ledger, inventory) | ❌ never | ✅ entirely |
| Internal business financial ledger | ❌ never — this is customer data | ✅ MultiDukkan's core |

The bottom two rows are the firewall: **subscription billing lives in the platform layer; the merchant's own accounting ledger lives in MultiDukkan's domain and never mixes** (see pricing doc §"three ledgers").

---

## 6. Feature entitlements & usage limits (mechanism)

Products need to gate features and enforce limits based on the active plan. Design the mechanism once, reuse per product:

- **Entitlements** = a resolved map of `feature → allowed?` and `limit → number` for a tenant, derived from its active subscription's plan (+ any overrides). Cache per request.
- **Enforcement points in MultiDukkan** (examples, all real limits worth considering): number of **stores/warehouses**, number of **users**, number of **products**, monthly **orders**, access to **AI endpoints**, access to **reports/audit log**, **multi-store** at all.
- **Where it plugs in:** a middleware / policy-adjacent check (`EnsureEntitled:feature` or a `Gate`), called in controllers/FormRequests at creation points — mirroring how `tenant_id` checks already work. It must **fail safe** (deny/limit) and return a clean, upgrade-oriented 402/403, never a 500.
- **Read model for the frontend:** extend `/me` (or a new `/entitlements`) so the SPA can hide/disable gated UI — but the **server is the source of truth**; UI gating is convenience only.

Anti-overengineering: start with a **static plan→entitlements map in config/code**, not a dynamic feature-flag service. Only the limits that map to a real plan difference need to exist at launch.

---

## 7. Security & isolation implications

- The billing/account layer is **new privileged surface**. Subscription state, provider webhooks, and entitlement checks must be tenant-isolated and tamper-resistant (a tenant must never set its own plan/entitlements via the API).
- Webhooks from the payment provider are **unauthenticated by session** — they must be verified by signature and are the *only* writer of paid subscription state (see pricing doc §webhooks).
- Fix the platform's dependence on the app's known gaps first: the missing **global tenant scope** (SECURITY-AUDIT H-02) becomes more dangerous once billing rides on `account_id`/`tenant_id`. Address entitlement checks with the same rigor as tenant checks.
- Marketing sites must not share the app's session/CORS surface (see public-websites §7).

---

## 8. Reusability without overengineering — the rules

1. **Model the `account` seam now; build nothing else speculative.** One product, one login, one API.
2. **Namespace platform code** (`Platform\Billing`, `Platform\Entitlements`) so it can be extracted later — don't extract it now.
3. **Static plan→entitlement config**, not a feature-flag platform.
4. **Tenant resolves from the user**, not the hostname — no per-tenant subdomains yet.
5. **No shared identity service, no SSO, no multi-product org model** until product #2 is real and paying.
6. Every abstraction must earn itself against a *current* need; "future product X might want it" is explicitly not a justification (project anti-goal).

---

**Related documents:** [`./pricing-and-billing.md`](./pricing-and-billing.md) (the billing detail this doc frames), [`./public-websites.md`](./public-websites.md) (domains/marketing), [`../06-domain/README.md`](../06-domain/README.md) (tenant model + ERD), [`../01-architecture/decisions/ADR-001-sanctum-token-auth.md`](../01-architecture/decisions/ADR-001-sanctum-token-auth.md), [`../10-roadmap/roadmap.md`](../10-roadmap/roadmap.md) (billing "jumps the queue" item), `docs/security/SECURITY-AUDIT.md` (H-02 global scope).

**Future improvements:** promote the `account`/entitlement mechanism to Tier 1 once built; write an ADR for the API-host decision (shared vs per-product) when product #2 is on the horizon.

**Open questions:** (1) Shared API host vs per-product — defer until product #2. (2) Does `account` get built with billing (recommended) or later? (3) Which limits are actually plan-differentiated at launch (input needed from pricing decision).

**Last review checklist:** [ ] still one product (if not, revisit shared-service decision), [ ] no speculative platform code shipped, [ ] `account` seam still minimal. Last reviewed: 2026-07-19 (design only).
