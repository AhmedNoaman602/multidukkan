# MultiDukkan — Security Audit

- **Date:** 2026-07-19
- **Reviewer role:** Senior application security engineer (code-level review)
- **Scope:** Laravel backend (`multidukkan`) + React frontend (`multidukkan-frontend`) — routes, controllers, services, models, policies, form requests, middleware, migrations, `config/*`, `.env.example`, `composer.json`, `package.json`, Vite/build config, git-tracked files and git history.
- **Method:** Manual code review that traces complete authorization and tenant-isolation paths end to end — not just checking that a `tenant_id` column or a policy call exists, but following each client-supplied ID to the query that uses it and attempting to construct a cross-tenant read/write. No code was modified. No exploits were run.
- **Prior context:** Supersedes the 2026-07-08 financial audit, whose P0s (policies checking roles only, controllers bypassing FormRequests) are **remediated**.

## Severity scheme

| Badge | Meaning |
|---|---|
| 🔴 **Critical** | Fix before production (before any real data / go-live) |
| 🟠 **High** | Fix before the first paying customer |
| 🟡 **Medium** | Should fix soon |
| 🔵 **Low** | Improvement / hardening |

---

## Executive Summary

I traced every route that binds or accepts an ID, following it through validation → policy → controller → service → query, and actively tried to make Tenant A touch Tenant B's data. **I found no working cross-tenant read, write, modify, or delete path in the current code.** Tenant isolation is enforced redundantly (policies check `tenant_id`, controllers re-check inline, FormRequests use `BelongsToTenant`/`OrderBelongsToCustomer`), and the one unscoped-looking write (`supplier_id` on products) is inert dead code. The detailed trace is in [§ Tenant Isolation — Adversarial Trace](#tenant-isolation--adversarial-trace).

The isolation is currently correct but **structurally fragile**: it depends on every controller *remembering* to check, because there is no model-level global tenant scope. That is the main architectural risk.

The single Critical item, Laravel Telescope shipping enabled in production (it could persist plaintext login passwords into the database and exposed an endpoint), is **✅ resolved as of 2026-07-20** — see C-01. Everything else is High/Medium/Low.

### Findings at a glance

| Sev | ID | Title |
|-----|----|-------|
| 🔴 Critical | C-01 | Telescope registered/enabled in production — logs credentials, exposes `/telescope` — ✅ Resolved |
| 🟠 High | H-01 | No model-level global tenant scope — isolation depends on never forgetting a manual check |
| 🟡 Medium | M-01 | Refund / payment double-spend race (no `lockForUpdate` between validation and write) |
| 🟡 Medium | M-02 | Direct inventory-quantity writes bypass the append-only `inventory_transactions` log |
| 🟡 Medium | M-03 | No rate limiting on authenticated endpoints — AI endpoints enable cost/DoS abuse |
| 🟡 Medium | M-04 | MySQL `engine => null` does not force InnoDB — transactional/FK guarantees unenforced |
| 🟡 Medium | M-05 | Supplier-payment reversal is not idempotent (concurrent double-reverse) |
| 🔵 Low | L-01 | `.env.example` ships `APP_DEBUG=true` / `APP_ENV=local` |
| 🔵 Low | L-02 | Auth token stored in `localStorage` (XSS token theft) |
| 🔵 Low | L-03 | `ledger_entries.customer_id` `cascadeOnDelete` can erase append-only history |
| 🔵 Low | L-04 | `store_manager` can assign staff to any store in the tenant |
| 🔵 Low | L-05 | Unit create/delete have no role check |
| 🔵 Low | L-06 | AI chat/insights accept unbounded user text (prompt injection, self-scoped) |
| 🔵 Low | L-07 | Mass-assignment safety depends entirely on FormRequest discipline |

---

## Tenant Isolation — Adversarial Trace

This section documents the actual paths traced and the cross-tenant attacks attempted, so the conclusion is verifiable rather than asserted.

### How isolation is currently enforced (three overlapping layers)

1. **Policies** (`app/Policies/*`) — every `view/update/delete` checks `$user->tenant_id === $model->tenant_id`.
2. **Inline controller re-checks** — most actions also do `if ($model->tenant_id !== auth()->user()->tenant_id) return 403;` after route-model binding.
3. **FormRequest rules** — foreign keys are validated with `BelongsToTenant(Model::class, $tenantId)` (checks the row exists *and* shares the tenant) or, for payments, chained ownership rules.

There is **no** global Eloquent scope, so route-model binding (`{order}`, `{customer}`, …) resolves by primary key across the whole table; layers 1–2 are what stop cross-tenant access.

### Attack 1 — Bind another tenant's model by ID (IDOR/BOLA)

*Attempt:* As Tenant A, call `GET/PATCH/DELETE /{resource}/{id}` with an ID owned by Tenant B, for every bound route: stores, orders, order-items, payments, customers, products, warehouses, inventory, purchase-orders, suppliers, supplier-payments, units, supplier-products, audit-batches.

*Result: blocked on all.* Every bound action either calls a policy that checks `tenant_id` or re-checks inline (usually both). Verified action-by-action:
- `OrderController` show/update/destroy/updateItem/addItem — policy + inline tenant check.
- `orders/{order}/items/{item}` — additionally verifies `$item->order_id === $order->id` (a foreign item 404s), closing the previously-flagged nested-binding hole.
- Customer, Product, Store, Warehouse, Inventory, Supplier, PurchaseOrder, SupplierPayment controllers — same pattern.
- `SupplierProductController` — `abort_if` on both `$supplier->tenant_id` and `$product->tenant_id` mismatch; `bulkAttach` filters product IDs to the tenant and 403s otherwise.
- `AuditLogController` — `tenant_admin` only, every union query filtered by `tenant_id`; `inventoryBatch` scoped by `tenant_id`.
- `UnitController::destroy` — inline tenant check.

### Attack 2 — Submit another tenant's ID inside a request body (mass foreign-key injection)

*Attempt:* Pass foreign `store_id`, `customer_id`, `product_id`, `warehouse_id`, `supplier_id`, `purchase_order_id`, `order_id` in create/update bodies.

*Result: blocked.* I grepped every `exists:`/`Rule::exists` rule and traced each:
- `StoreOrderRequest`, `StoreOrderItemRequest`, `StorePurchaseOrderRequest`, `StoreInventoryRequest`, `StoreWarehouseRequest`, `StoreSupplierPaymentRequest`, `AutoPaymentRequest`, `StoreUserRequest`, `UpdateProductRequest.stocks[]` — all foreign keys carry `BelongsToTenant`.
- **`StorePaymentRequest.order_id`** uses a bare `Rule::exists('orders','id')` (not tenant-scoped) — **but** it is chained with `OrderBelongsToCustomer($customer_id)`, and `customer_id` is scoped to the tenant. For the order to validate, `order.customer_id` must equal the caller's tenant-owned customer; a Tenant B order references a Tenant B customer, so it fails. Isolation holds *transitively*. (Fragile-by-design — see recommendation to scope `order_id` directly.)
- **`RefundCustomerRequest.order_id`/`payment_id_target`** use bare `exists:` — **but** `withValidator()` re-checks `order.tenant_id === customer.tenant_id` and `payment.customer_id === customer.id`. Holds.
- **`StoreOrderRequest.created_by`** is `nullable|exists:users,id` (not tenant-scoped) — **but** `OrderService` overwrites it with `auth()->id()`, so the client value is discarded. Inert.
- **`StoreProductRequest.supplier_id`** is `nullable|exists:suppliers,id` (not tenant-scoped), and `ProductController::update` writes `$request->supplier_id` — **but** `products` has no `supplier_id` column and it is not in `Product::$fillable`, so Eloquent silently drops it. **Dead code, not a leak** (product↔supplier links go through the tenant-checked `supplier_products` pivot instead). Recommend deleting the dead rule/assignment to avoid future confusion.

### Attack 3 — Reach another tenant's data through a service or relationship

*Attempt:* Get a service to `findOrFail` a foreign ID, or traverse a relationship that isn't scoped.

*Result: blocked.* `PaymentService`/`OrderService`/`SupplierPaymentService` only `findOrFail` IDs that already passed the tenant-scoped FormRequest (Attack 2). `LedgerService` methods receive `tenant_id` from the controller, which derives it from the authorized model or `auth()->user()`. Read aggregations (`DashboardController`, `ReportController`, `SearchController`, `AIController::insights/chat`, `LedgerEntryController::summary`) all begin from `where('tenant_id', auth()->user()->tenant_id)` or a relationship rooted on an authorized model. The `AuditLogController` name-lookup `whereIn('id', $ids)` calls are unscoped but the IDs originate from rows already filtered by `tenant_id`, so no foreign data is reachable.

### Conclusion

No cross-tenant read/write path is currently reachable. The residual risk is **H-01** (no global scope): the transitive/inline checks are correct today but one forgotten check on a future endpoint becomes an instant IDOR. Recommend converting isolation from a convention into an enforced default.

> **Intra-tenant note (not cross-tenant):** store-level scoping is intentionally inconsistent — `OrderController`/`InventoryController`/`PaymentController` narrow by `store_id` for store users, while products/customers/units are tenant-wide by design. This is an access-design choice within a tenant, not an isolation defect; flagged only so it's a conscious decision (see L-04 for the one place it enables minor over-permission).

---

## 🔴 Critical

### C-01 — Laravel Telescope is registered and enabled in production
*(previously tracked as H-01)*

**Status: ✅ Resolved 2026-07-20** — see Resolution below.

- **Severity:** 🔴 Critical — fix before production.
- **Files:** `bootstrap/providers.php` (registers `TelescopeServiceProvider` unconditionally); `config/telescope.php:19` (`'enabled' => env('TELESCOPE_ENABLED', true)`); `config/telescope.php:45` (`'path' => 'telescope'`); `app/Providers/TelescopeServiceProvider.php` (`gate()`, `hideSensitiveRequestDetails()`); `composer.json:14` (`laravel/telescope` in `require-dev`).
- **Weakness:** `TelescopeServiceProvider` is registered for all environments and Telescope defaults to `enabled = true`. Its `RequestWatcher` records request bodies into `telescope_entries`. `hideSensitiveRequestDetails()` hides only `_token` and cookie headers — **not** `password`/`password_confirmation` — so plaintext credentials submitted to `/api/login` and `/api/register` can be persisted to the database. The `/telescope` UI is reachable and gated only by `viewTelescope`, whose allow-list is empty (currently denies everyone) — one edit or an `APP_ENV=local` slip turns that into full request/DB/credential exposure. Because `bootstrap/providers.php` hard-references the class, production must deploy *with* dev deps, so Telescope is present in prod.
- **Why it matters:** Storing plaintext passwords and full query traffic in an app table is a serious data-handling failure; the endpoint is one misconfiguration from exposing all tenant data.
- **Exploitation scenario:** App deployed with dev deps, `TELESCOPE_ENABLED` left default. Every login writes the user's password into `telescope_entries`. Anyone with DB/backup read access, or `/telescope` access if the gate is ever relaxed or `APP_ENV` flips to `local`, harvests credentials and cross-tenant data.
- **Affected data:** All request bodies (incl. passwords), all DB queries/bindings, across every tenant.
- **Recommended fix:** (1) Register `TelescopeServiceProvider` only in `local` (conditional registration; drop it from `bootstrap/providers.php`). (2) Set `TELESCOPE_ENABLED=false` in production regardless. (3) Add `password`, `password_confirmation` to `Telescope::hideRequestParameters`. (4) Deploy prod with `composer install --no-dev` once the provider is conditional.
- **Verification:** In a prod-like build, `/telescope` → 404; a login writes no row to `telescope_entries`; `php artisan about` shows Telescope disabled.
- **Resolution (2026-07-20):** Fixed with one more layer than originally recommended above — this write-up didn't account for Laravel's package auto-discovery, which registers the *vendor's own* `Laravel\Telescope\TelescopeServiceProvider` (the class that actually owns the `/telescope` routes and watchers) independently of `bootstrap/providers.php`, any time the package is physically present in `vendor/`. Simply removing the manual registration line would not have closed that path. Implemented:
  1. `composer.json` — added `laravel/telescope` to `extra.laravel.dont-discover`, suppressing auto-discovery in every environment regardless of `--no-dev`; regenerated `bootstrap/cache/packages.php` via `composer dump-autoload` to confirm it took effect.
  2. `bootstrap/providers.php` — removed the unconditional `App\Providers\TelescopeServiceProvider::class` registration.
  3. `app/Providers/AppServiceProvider.php::register()` — now explicitly registers both `Laravel\Telescope\TelescopeServiceProvider` and `App\Providers\TelescopeServiceProvider`, but only when `$this->app->environment('local')` is true (required since discovery is now suppressed).
  4. `app/Providers/TelescopeServiceProvider.php::hideSensitiveRequestDetails()` — removed the now-redundant `environment('local')` early-return (the provider only ever loads in `local` now, so the guard would have made hiding permanently dead code) and added `password`/`password_confirmation` to `hideRequestParameters`, as defense-in-depth in case the local-only guard is ever loosened later.
  5. `TELESCOPE_ENABLED=false` documented in production `.env` (not committed); `.env.example` documents the flag with a comment that it must be `false` outside local/staging.
  6. `README.md` — added a Deployment section noting `composer install --no-dev` and `TELESCOPE_ENABLED=false` as required (currently manual, unenforced by CI) production steps.
  - Verified locally: with `APP_ENV=production` + `TELESCOPE_ENABLED=false`, `php artisan route:list --path=telescope` returns no rows and `GET /telescope` → 404. With `APP_ENV=local`, Telescope continues to work normally for development.

---

## 🟠 High

### H-01 — Tenant isolation has no model-level global scope
*(previously tracked as H-02)*

- **Severity:** 🟠 High — fix before first paying customer.
- **Files:** all models in `app/Models/` (no global `tenant_id` scope); every controller manually filters/re-checks (see [trace above](#tenant-isolation--adversarial-trace)).
- **Weakness:** Route-model binding resolves by PK across the whole table; isolation depends on each action remembering a policy and/or inline `tenant_id` check. Coverage is **complete today** (verified), but the first endpoint that binds a model and forgets the check is an instant cross-tenant IDOR. The heavy `authorize()` + inline duplication is itself a symptom of there being no single enforcement layer.
- **Why it matters:** Relying on human discipline for the primary security boundary does not scale as endpoints are added (Phase 3 adds stock transfers). One miss leaks another business's customers, orders, ledger, and stock.
- **Exploitation scenario (latent):** A future `GET /widgets/{widget}` returns the model without a tenant re-check → Tenant A reads Tenant B by incrementing the ID.
- **Recommended fix:** Add a `BelongsToTenant` trait that applies a global Eloquent scope filtering `tenant_id = auth()->user()->tenant_id` and stamps `tenant_id` on create; apply to all tenant-owned models. Keep policies for role logic; the inline `!== tenant_id` checks can then be removed. Also directly scope `StorePaymentRequest.order_id` to the tenant (belt-and-braces), and delete the dead `supplier_id` product rule/assignment.
- **Verification:** For every `{model}` route, authenticate as Tenant A and request a Tenant B ID → expect 404/403. Add an automated cross-tenant matrix test so regressions fail CI.

---

## 🟡 Medium

### M-01 — Refund / payment double-spend race condition

- **Severity:** 🟡 Medium (high financial impact × low likelihood).
- **Files:** `app/Http/Requests/RefundCustomerRequest.php` (`withValidator` computes refundable as a read); `app/Services/LedgerService.php:373/359/383` (`refundableForPayment`/`refundableForOrder`/`issueRefund`, which `increment('refunded_amount', …)` with no lock); `app/Services/PaymentService.php` `processDirectPayment`.
- **Weakness:** Refundable amount is computed during validation (a plain `SELECT`), then `issueRefund` increments `refunded_amount` with **no `lockForUpdate`** and no re-check inside the write transaction. No DB constraint stops `refunded_amount` exceeding `amount`. Two concurrent refunds for the same payment/order both pass the check and both execute → over-refund (real cash paid twice). Same TOCTOU shape in `processDirectPayment` (lower impact — excess becomes credit).
- **Why it matters:** Double refund is direct cash loss and makes the ledger internally inconsistent — the exact failure class the "one source of truth" rule exists to prevent.
- **Exploitation scenario:** Two identical `POST /customers/{id}/refund` fired within milliseconds for a fully-refundable payment → refunded twice.
- **Recommended fix:** Re-load the payment/order `->lockForUpdate()` at the top of `issueRefund`/`processDirectPayment` and recompute refundable there, throwing if exceeded. Add `CHECK (refunded_amount <= amount)` (MySQL 8). Consider idempotency keys on refunds.
- **Verification:** Concurrency test firing two simultaneous refunds → exactly one succeeds; `refunded_amount <= amount` always holds.

### M-02 — Direct inventory-quantity writes bypass the append-only transaction log

- **Severity:** 🟡 Medium.
- **Files:** `app/Http/Controllers/Api/V1/ProductController.php` `update()` (`stocks[]` block writes `Inventory` quantity directly); `app/Http/Controllers/Api/V1/InventoryController.php` `update()` (`$inventory->update($request->validated())`). Contrast `app/Services/InventoryService.php`, which always writes an `InventoryTransaction`.
- **Weakness:** `inventory_transactions` is the append-only audit log the activity feed reconstructs from, but `PUT /products/{id}` (via `stocks[]`) and `PUT /inventory/{id}` overwrite `inventory.quantity` with **no** transaction row — untracked stock change, no user/reason attribution.
- **Why it matters:** Breaks the inventory-integrity guarantee; a manager can zero-out or inflate stock invisibly, defeating shrinkage/theft investigations.
- **Exploitation scenario:** Edit a product, set a warehouse quantity 2 → 200; nothing appears in `GET /audit-log`.
- **Recommended fix:** Route all quantity changes through `InventoryService` so a transaction is always written; or remove `quantity` from these update paths and require the adjust endpoint.
- **Verification:** Change stock via product/inventory edit → assert a matching `inventory_transactions` row and an audit-log entry exist.

### M-03 — No rate limiting on authenticated endpoints (AI cost/DoS)

- **Severity:** 🟡 Medium.
- **Files:** `routes/api.php` — only `/login` and `/register` carry `throttle:5,1`; the whole `auth:sanctum` group (incl. `/ai/describe-product`, `/ai/insights`, `/ai/chat`) has no throttle.
- **Weakness:** Authenticated users can call any endpoint uncapped. AI endpoints proxy a paid LLM (Groq via Prism) with no per-user/tenant quota.
- **Why it matters:** One token can loop `/ai/chat` to run up unbounded Groq cost or hammer aggregation-heavy report/dashboard endpoints.
- **Recommended fix:** Global `throttle:` on the API group + a tighter named limiter for AI routes; consider a per-tenant daily AI quota.
- **Verification:** Exceed the limit → HTTP 429.

### M-04 — MySQL connection uses `engine => null` (transactional/FK guarantees not enforced)

- **Severity:** 🟡 Medium.
- **Files:** `config/database.php:60` (`'engine' => null`), `:59` (`'strict' => true`).
- **Weakness:** Tables are created with the server's default engine rather than an explicit InnoDB. Every money/stock guarantee relies on `DB::transaction` atomicity + FKs; on a MyISAM-default server those silently become no-ops with no error. Latent (MySQL 8 defaults to InnoDB and the app already depends on it) but unenforced.
- **Recommended fix:** Set `'engine' => 'InnoDB'` on the MySQL connection; add a deploy check asserting critical tables report `ENGINE=InnoDB`.
- **Verification:** `SHOW TABLE STATUS` → all business tables InnoDB; a mid-transaction FK violation rolls back fully.
- **Test-fidelity note:** `.env.example` uses `DB_CONNECTION=sqlite` and tests run on SQLite, which won't reproduce MySQL engine/strict/FK behavior — consider a MySQL CI job for money/stock tests.
- **Status:** Fixed — `config/database.php` now sets `'engine' => 'InnoDB'` on both the `mysql` and `mariadb` connections. `php artisan db:verify-engine` (`app/Console/Commands/VerifyDatabaseEngine.php`) queries `information_schema.TABLES` and fails (non-zero exit) if any table isn't InnoDB; it must run as a deploy step after `migrate` and before traffic is cut over. No CI job exists in this repo yet (no `.github/workflows`) — the MySQL-backed CI job in the test-fidelity note above remains a follow-up, not covered by this fix.

### M-05 — Supplier-payment reversal is not idempotent

- **Severity:** 🟡 Medium (low likelihood).
- **Files:** `SupplierPaymentController::destroy`; `SupplierPaymentService::reversePayment`.
- **Weakness:** `reversePayment` posts a `SUPPLIER_PAYMENT_REVERSAL` ledger entry for the full amount then hard-deletes the payment, with no lock or "already reversed" guard. Two concurrent `DELETE /supplier-payments/{id}` can both resolve the model and both post a reversal → supplier balance credited twice.
- **Recommended fix:** `lockForUpdate()` the payment inside the transaction and abort if already gone/reversed; or guard with an idempotency check on `(payment_id, SUPPLIER_PAYMENT_REVERSAL)`.
- **Verification:** Two concurrent reversals → exactly one reversal entry.

---

## 🔵 Low

### L-01 — `.env.example` ships unsafe debug defaults
- **File:** `.env.example` (`APP_ENV=local`, `APP_DEBUG=true`). `config/app.php:42` correctly defaults `debug` to `false`, so this is only a template risk.
- **Fix:** Ship the example with `APP_ENV=production`/`APP_DEBUG=false`; verify the real prod `.env` sets `APP_DEBUG=false`.

### L-02 — Auth token in `localStorage`
- **Files:** `multidukkan-frontend/src/api/axios.js`, `src/pages/Login.jsx:18`, `src/pages/Register.jsx:23`.
- **Weakness:** Bearer token in `localStorage` is JS-readable; any future XSS (none found — no `dangerouslySetInnerHTML`/`innerHTML`) exfiltrates it. Defense-in-depth item.
- **Fix:** Acceptable given no XSS sinks; for stronger posture consider httpOnly-cookie SPA session mode + strict CSP.

### L-03 — `cascadeOnDelete` on `ledger_entries.customer_id` / `store_id`
- **File:** `database/migrations/2026_03_01_210754_create_ledgers_entries_table.php:17-18`.
- **Weakness:** The append-only ledger cascade-deletes if a customer/store is ever hard-deleted, erasing financial history. Mitigated today by soft deletes on `Customer`.
- **Fix:** `restrictOnDelete`/`nullOnDelete` for ledger FKs; allow customer deletion only when balance is settled.

### L-04 — `store_manager` can assign staff to any store in the tenant
- **Files:** `UserController::store`; `StoreUserRequest` (`store_id` validated with `BelongsToTenant` only). Role escalation is correctly blocked (role validated against `allowedRoles`); this is intra-tenant scoping, not privilege escalation.
- **Fix:** When the actor is `store_manager`, force `store_id = auth()->user()->store_id`.

### L-05 — Unit create/delete lack role checks
- **File:** `UnitController` (`store`/`destroy` check tenant only; no `UnitPolicy`).
- **Weakness:** Any authenticated tenant user (incl. `store_staff`) can add/delete units. Low impact (tenant-scoped reference data).
- **Fix:** Restrict to `tenant_admin`/`store_manager`.

### L-06 — AI endpoints accept unbounded user text (prompt injection)
- **Files:** `AIController` (`chat`/`insights`/`describeProduct`); `AIService`.
- **Weakness:** User strings are interpolated into LLM prompts. Impact limited: the model has no tools/side-effects and the catalog context is tenant-scoped, so worst case is a bad answer to the user's own query. `chat` caps at 500 chars; the others are looser.
- **Fix:** Keep treating LLM output as untrusted display text (React escapes it); cap all AI inputs; combine with M-03 rate limiting.

### L-07 — Mass-assignment safety depends on FormRequest discipline
- **Files:** e.g. `app/Models/Order.php` (`$fillable` includes `tenant_id`, `store_id`, `total`); `OrderService::updateOrder` does `$order->update($data)`.
- **Weakness:** Financial/ownership columns are mass-assignable; safe today only because callers pass `$request->validated()` and the FormRequests whitelist safe fields (`UpdateOrderRequest` allows only `notes/order_date/discount`). A future `$request->all()` or a looser FormRequest would allow tampering.
- **Fix:** Remove `tenant_id`/`total` from `$fillable` where writes should only flow through services (stamp them explicitly).

---

## Positive Findings (verified good)

- **July P0s remediated** — policies check `tenant_id`; payment/refund flows use proper FormRequests; nested order-item binding verifies `order_id` match.
- **No working cross-tenant path** — see the [adversarial trace](#tenant-isolation--adversarial-trace); every foreign-ID vector is scoped, transitively protected, re-checked, or inert.
- **No SQL injection** — all queries use Eloquent/bindings; `like "%$search%"` passes the string as a binding; `selectRaw`/`whereRaw`/`orderByRaw` contain only static SQL, no interpolated user input.
- **No committed secrets** — `.env*` git-ignored; history scan for `gsk_…`/`sk-…`/`AKIA…` and for `.env` commits returned nothing; keys read via `env()`.
- **Negative stock fails safe** — `inventory.quantity` is `unsignedInteger` and `strict => true`, so an over-deduction underflow errors and rolls back rather than going negative (why the check-then-deduct TOCTOU doesn't oversell — worst case an unhandled 500; consider catching it for a clean 422).
- **Passwords hashed** (`bcrypt`, `BCRYPT_ROUNDS=12`); Sanctum tokens; login/register throttled; unauthenticated → JSON 401.
- **Order creation atomic**, invoice numbers collision-safe (unique `(tenant_id, invoice_number)` + retry).
- **Low frontend XSS surface** — React, no raw HTML injection; dependencies current, none flagged.
- **CORS** restricted to explicit origins even with `supports_credentials: true`.

---

## Architectural / Code-Quality Recommendations (not vulnerabilities)

1. **Global tenant scope** (H-01) — make isolation the default, remove duplicated checks.
2. **Single write-path for stock and money** — direct `Inventory::update(['quantity'])` (M-02) and any non-service financial write should be impossible by construction.
3. **MySQL-backed CI for money/stock tests** (M-04) — catch SQLite-vs-MySQL behavioral gaps.
4. **Idempotency keys** for refund/payment/reversal — the structural fix for the M-01/M-05 race class.
5. **Delete dead code** — the `supplier_id` product validation rule + assignment (no column, silently dropped) to prevent future confusion.
6. **Consistent error envelopes** — ensure unexpected DB errors (e.g. unsigned underflow) surface as clean 4xx, never a 500 that could leak detail if `APP_DEBUG` is ever misconfigured.

---

## Methodology & Coverage

Traced: all 20 API controllers; all 10 policies; every FormRequest + both custom Rules; `OrderService`, `PaymentService`, `SupplierPaymentService`, `InventoryService`, `LedgerService` (refund/adjust/reversal paths); route-model binding in `routes/api.php`; the `exists:`/`Rule::exists` inventory of foreign-key validations (each followed to its query); `products`/`Product` schema to confirm the `supplier_id` write is inert; migrations for FK cascades, unique indexes, and column constraints; `config/{app,cors,sanctum,database,telescope,services}.php`; `.env.example`; `composer.json`/`package.json`; the React app's auth/token handling and HTML-injection surface; and git history for committed secrets.

Not covered: runtime/dynamic testing (no requests sent), production server/TLS config, and third-party service-side security. The concurrency findings (M-01, M-05) are from static reasoning and should be confirmed with a concurrency test harness.

---

**Related documents:** `docs/architecture/saas-platform.md` (H-01 becomes more load-bearing once billing rides on `account_id`), `docs/plans/pricing-and-billing.md` (webhook/entitlement rigor references M-01/M-04/M-05/C-01/H-01).
