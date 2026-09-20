# MultiDukkan

A multi-tenant inventory and sales management system for small retail businesses, built as a Laravel REST API with a separate React frontend.

This repository is the **backend**: the REST API, business services and database. The React client lives in [multidukkan-frontend](https://github.com/AhmedNoaman602/multidukkan-frontend). The two together are MultiDukkan.

One tenant can run several stores and warehouses, sell in multiple units, track customer credit, purchase from suppliers, and see where every unit of stock and every pound went. It is being built for a real family retail business.

**Status:** personal project, in active development. Not deployed, no users, no CI pipeline. The functionality described below is implemented in this repository, with automated tests concentrated on the business and authorization rules.

---

## What it does

- **Multi-store, multi-warehouse inventory** with per-warehouse stock levels and reorder thresholds
- **Orders** with line-item snapshots, discounts, refunds, and walk-in customers
- **Payments and customer credit** tracked through an append-only financial ledger
- **Purchasing** — suppliers, purchase orders, supplier payments, and weighted-average cost tracking
- **Dual units** — sell the same product by piece or by box, with automatic conversion
- **Five-tier customer pricing** (`price_a` through `price_e`)
- **Reports, dashboards, expenses, and audit logs**
- **Arabic and English**, with right-to-left support and per-request timezone handling
- **LLM features** — AI-generated product descriptions, business insights, and a chatbot over business data

---

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.2+ |
| Database | MySQL (InnoDB and strict mode required) |
| Auth | Laravel Sanctum, token-based |
| AI | Prism PHP against Groq (`llama-3.3-70b-versatile`) |
| Testing | PHPUnit 11, feature tests against real MySQL |
| Frontend | React 19 (JavaScript), Vite, React Router 7, TanStack Query 5, Tailwind — separate repo: [multidukkan-frontend](https://github.com/AhmedNoaman602/multidukkan-frontend) |

At a glance: **87 REST endpoints** across 21 controllers, 19 models, 55 migrations, **390 feature tests** in 33 files.

---

## Architecture

A request crosses five layers, each with one job:

```
React frontend
      |
    Axios            single client: token, X-Locale, X-Timezone, 401 handling
      |
Laravel REST API   route -> FormRequest -> thin controller
      |
Business services  OrderService, LedgerService, InventoryService, ...
      |
    MySQL          tenant-scoped, InnoDB, strict mode
```

The decisions worth knowing about, and where they are documented.

### Tenant isolation is enforced at the model layer

A global Eloquent scope ([`ScopedToTenant`](app/Models/Concerns/ScopedToTenant.php)) is applied to **15 models**. It filters queries by `tenant_id` and stamps tenant ownership on insert, so isolation does not depend on a developer remembering to add a `where` clause to each query.

This replaced an earlier design where each controller checked `tenant_id` by hand. That pattern was flagged as structurally fragile during a code-level security review — see finding H-01 in [`docs/security/SECURITY-AUDIT.md`](docs/security/SECURITY-AUDIT.md), which recommended converting isolation from a convention into an enforced default.

### Authorization is layered

Three roles — `tenant_admin`, `store_manager`, `store_staff` — enforced by **11 policies** plus two named capability gates (`view-cost-data`, `view-reports`). Policies answer "may this user touch this record"; gates answer "may this role do this kind of thing at all". Store-level isolation means a manager in one store cannot read or modify another store's orders, payments, or refunds.

See [ADR-002](docs/01-architecture/decisions/ADR-002-string-role-column.md) and [ADR-009](docs/01-architecture/decisions/ADR-009-capability-gates.md).

### Money has one source of truth

`ledger_entries` is append-only. Customer balances and order payment status are **derived** from it rather than stored. `LedgerService` exposes exactly two methods that modify an existing entry — `adjustPayment` and `adjustOrderCharge` — and every other correction appends a reversal.

The rule exists because the same balance calculation had previously been reimplemented in several controllers, which then disagreed with one another. All financial math now lives in `LedgerService`.

See [ADR-003](docs/01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md), [ADR-006](docs/01-architecture/decisions/ADR-006-ledger-mutability-boundaries.md), and [`docs/07-business-rules/financial-calculations.md`](docs/07-business-rules/financial-calculations.md).

### Stock is an event log, not just a number

`inventory.quantity` is the current count; `inventory_transactions` is the append-only history of how it got there. Sales, restocks, and manual adjustments each write a transaction row referencing what caused the movement.

One gap is known and tracked: `InventoryService::setStock` writes a quantity directly without logging a transaction. That is finding M-02 in the security audit, still open.

Order items snapshot the product name and price at sale time, so editing a product does not rewrite past invoices ([ADR-005](docs/01-architecture/decisions/ADR-005-snapshot-pricing.md)). Purchase receipts recalculate weighted-average cost ([ADR-008](docs/01-architecture/decisions/ADR-008-weighted-average-costing.md)).

### Layers are strict

```
Route → FormRequest (validation) → Controller (thin) → Service (business logic) → Model
                                         ↓
                                   API Resource (response shaping)
```

Controllers authorize and delegate; business logic lives in services. Validation lives in 33 FormRequest classes, and 13 API Resources shape responses for the main entities. Two deviations exist today: `AIController` validates inline rather than through a FormRequest, and a number of endpoints — reporting, search, auth and some others — return arrays directly instead of going through a Resource.

See [`docs/01-architecture/backend-architecture.md`](docs/01-architecture/backend-architecture.md).

---

## Frontend

The React client lives in its own repository, [multidukkan-frontend](https://github.com/AhmedNoaman602/multidukkan-frontend). It is a JavaScript single-page app (React 19, Vite, React Router 7, Tailwind) with no server-side rendering — it talks to this API and nothing else.

**One API boundary.** Every request goes through a single Axios instance; no component imports Axios or calls `fetch` directly. That client is where cross-cutting concerns live, so they exist in exactly one place:

- attaches the Sanctum bearer token
- sends `X-Locale` so validation errors come back in the user's language
- sends the browser's IANA timezone as `X-Timezone`, which the API's `SetTimezone` middleware uses to decide which calendar day a date filter means — timestamps themselves stay UTC end to end
- on a `401` that is not a login attempt, clears the stored session and redirects, so a revoked or expired token cannot leave the user on a page whose every request silently fails

**Authentication.** Login stores the token and user in `localStorage`. An `AuthGate` component re-fetches `/me` on every route change rather than trusting that cached copy, which is what keeps role and onboarding state correct after it changes server-side.

**Server state vs UI state.** TanStack Query owns everything read from the API (40 query sites); component state owns only UI concerns. Writes are deliberately not `useMutation` — they are direct calls on the shared client followed by explicit `invalidateQueries` on the affected keys.

**Role-aware routing.** `PrivateRoute` gates on the presence of a token. `RoleRoute` takes a capability predicate from `src/lib/permissions.js`, whose two functions mirror the server's `view-cost-data` and `view-reports` gates. Supplier, purchase-order and report routes sit behind it.

> **This is user experience, not security.** Hiding a route, a column or a cost price in React prevents a user from being offered a dead end that the API would reject anyway. It is not an access control. Authorization is enforced independently in the Laravel backend by policies and gates, and the cross-store exposure tests exercise that server-side. Anyone can edit client-side JavaScript; nobody can edit the policy layer.

**Arabic first.** A hand-written i18n layer — no i18n library — with Arabic as the default language and 20 translation namespaces per language. The provider sets `<html lang>` and `dir`, and components branch on direction for icons, drawer sides and sheet placement, so RTL is real layout behaviour rather than a mirrored stylesheet. The choice persists in `localStorage` and rides along on every request via `X-Locale`.

## Testing

```bash
php artisan test
```

**390 feature tests across 33 files**, run against **real MySQL rather than an in-memory SQLite database**, so foreign keys, transactions, and `STRICT_TRANS_TABLES` behave the way they will on a real server.

Coverage is concentrated where mistakes are expensive:

| Area | Tests |
|---|---|
| Role and permission matrix | `RoleTest` (47) |
| Ledger and financial correctness | `LedgerTest` (35) |
| Inventory movements | `InventoryTest` (24) |
| Timezone handling | `TimezoneTest` (23) |
| Discount rules and role ceilings | `DiscountTest` (23) |
| Monetary overflow boundaries | `MonetaryOverflowValidationTest` (12) |
| Cross-store data exposure | `CrossStoreExposureTest` (8), `CostDataVisibilityTest` (14) |

Test conventions are in [`docs/04-engineering-standards/testing-strategy.md`](docs/04-engineering-standards/testing-strategy.md).

This is the backend suite. **The frontend repository has no automated tests** — it is checked by `npm run build` and `npm run lint` only.

---

## Running it locally

**Requirements:** PHP 8.2+, MySQL, Composer.

```bash
git clone https://github.com/AhmedNoaman602/multidukkan.git
cd multidukkan
composer install
cp .env.example .env
php artisan key:generate
```

`.env.example` targets MySQL, so the only values you normally need to change are the database name and credentials:

```
DB_DATABASE=multidukkan
DB_USERNAME=root
DB_PASSWORD=
```

Create that database, then:

```bash
php artisan migrate --seed
php artisan serve
```

Seeding runs `DemoSeeder`, which builds a tenant with stores, warehouses, products, stock, customers, a supplier, and orders created through the real services rather than raw inserts — so the ledger, stock and invoice logic all run. It creates two users you can log in with:

| Email | Password | Role |
|---|---|---|
| `noaman@multidukkan.com` | `password123` | `tenant_admin` |
| `ahmed@multidukkan.com` | `password123` | `store_manager` |

These are local development fixtures, defined in plain text in `database/seeders/DemoSeeder.php`, and are not used by any deployed system. The seeder refuses to run when `APP_ENV=production`.

### Verifying the database is configured correctly

Two diagnostic commands ship with the project. Both are read-only.

```bash
php artisan db:verify-sql-mode    # STRICT_TRANS_TABLES active on connection and server?
php artisan db:verify-engine      # every table on InnoDB?
```

Strict mode matters: without it MySQL silently truncates overflowing monetary values instead of rejecting them. See [`docs/DATABASE_GUIDE.md`](docs/DATABASE_GUIDE.md).

### Running the frontend

The API serves JSON only; there are no Blade views, so you need the client to see a UI. Clone [multidukkan-frontend](https://github.com/AhmedNoaman602/multidukkan-frontend), `npm install`, `npm run dev`. Note that its API base URL is currently hardcoded to `http://multidukkan.test/api` in `src/api/axios.js` — change it there if you serve this API from somewhere else.

### Tests

Tests use a separate database named `multidukkan_test`, configured in `phpunit.xml`. Create it once:

```sql
CREATE DATABASE multidukkan_test;
```

---

## Documentation

This project is documented more heavily than most of its size, because writing the docs is also how I work out the domain. Start at [`docs/README.md`](docs/README.md).

| If you want | Read |
|---|---|
| A tour of the whole system | [`docs/DEVELOPER_GUIDE.md`](docs/DEVELOPER_GUIDE.md) |
| The invariants that must not break | [`docs/DOMAIN_RULES.md`](docs/DOMAIN_RULES.md) |
| Every table, column, and foreign key | [`docs/DATABASE_GUIDE.md`](docs/DATABASE_GUIDE.md) |
| Why a decision is the way it is | [9 ADRs](docs/01-architecture/decisions/) |
| Known rough edges, honestly ranked | [`docs/CODEBASE_NOTES.md`](docs/CODEBASE_NOTES.md) |
| The security review and what came out of it | [`docs/security/SECURITY-AUDIT.md`](docs/security/SECURITY-AUDIT.md) |

---

## Repository layout

```
app/
  Http/Controllers/Api/V1/   21 controllers
  Http/Requests/             33 FormRequests
  Http/Resources/            13 API Resources
  Services/                  business logic (Order, Ledger, Inventory, Payment, ...)
  Services/AI/               LLM integration
  Policies/                  11 authorization policies
  Models/                    19 models; Models/Concerns holds the ScopedToTenant scope
  Rules/                     BelongsToTenant, OrderBelongsToCustomer
  Console/Commands/          db:verify-sql-mode, db:verify-engine
database/migrations/         55 migrations
tests/Feature/               33 test files, 390 tests
docs/                        engineering documentation
```

---

## Before deploying

Nothing here is automated, because no CI pipeline exists in this repository. Treat it as a manual checklist:

- Run `composer install --no-dev --optimize-autoloader` — dev-only tooling (Telescope, Debugbar, Clockwork) should not reach a deployed `vendor/` directory.
- Set `APP_ENV=production`, `APP_DEBUG=false`, and `TELESCOPE_ENABLED=false`. `.env.example` ships the local-development values for all three. Telescope is additionally registered only when `APP_ENV=local` (`AppServiceProvider::register`), so a correct `APP_ENV` disables it regardless of the flag.
- Ensure MySQL runs with `STRICT_TRANS_TABLES` and InnoDB, verified with the two commands above. Laravel sets strict mode per session, but the server default is a separate setting.

Finding C-01 in the security audit — Telescope shipping enabled, which could persist plaintext login passwords — is the incident behind the first two items. It is marked resolved in the audit.

---

## Known limitations

- No deployment, no CI, no automated pre-deploy checks. The deployment steps above are manual.
- `setStock` bypasses the inventory transaction log (audit M-02, open).
- Remaining medium and low findings from the security audit are listed, with reasoning, in [`docs/security/SECURITY-AUDIT.md`](docs/security/SECURITY-AUDIT.md).
- The frontend has no automated tests, and its API base URL is hardcoded rather than read from an environment variable, so it cannot be pointed at another environment without a code change.
- The frontend ships as a single ~1.2 MB bundle (~319 kB gzipped) with no code splitting.

---

## License

No open-source license. This is a personal project published for reading and review, not for reuse.
