# MultiDukkan

A multi-tenant inventory and sales management system for small retail businesses, built as a Laravel REST API with a separate React frontend.

One tenant can run several stores and warehouses, sell in multiple units, track customer credit, purchase from suppliers, and see where every unit of stock and every pound went. It is being built for a real family retail business.

**Status:** personal project, in active development. Not deployed to production, no paying users, no CI pipeline. Everything described below exists in this repository and is covered by tests.

---

## What it does

- **Multi-store, multi-warehouse inventory** with per-warehouse stock levels and reorder thresholds
- **Orders** with line-item snapshots, discounts, refunds, and walk-in customers
- **Payments and customer credit** tracked through an append-only financial ledger
- **Purchasing** — suppliers, purchase orders, supplier payments, and weighted-average cost tracking
- **Dual units** — sell the same product by piece or by box, with automatic conversion
- **Five-tier customer pricing**
- **Reports, dashboards, expenses, and audit logs**
- **Arabic and English**, with right-to-left support and per-request timezone handling
- **LLM features** — AI-generated product descriptions, business insights, and a chatbot over business data

---

## Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.2 |
| Database | MySQL (InnoDB, strict mode required) |
| Auth | Laravel Sanctum, token-based |
| AI | Prism PHP against Groq (`llama-3.3-70b-versatile`) |
| Testing | PHPUnit 11, feature tests against real MySQL |
| Frontend | React 19 + Vite, in [multidukkan-frontend](https://github.com/AhmedNoaman602/multidukkan-frontend) |

At a glance: **87 REST endpoints** across 21 controllers, 19 models, 55 migrations, **389 feature tests** in 33 files.

---

## Architecture

The decisions worth knowing about, and where they are documented.

### Tenant isolation is enforced at the model layer

A global Eloquent scope ([`ScopedToTenant`](app/Models/Concerns/ScopedToTenant.php)) is applied to **15 models**. It filters every query by `tenant_id` and stamps tenant ownership on every insert, so isolation does not depend on a developer remembering to add a `where` clause.

This replaced an earlier design where each controller checked `tenant_id` by hand. That pattern was flagged as structurally fragile during a code-level security review — see finding H-01 in [`docs/security/SECURITY-AUDIT.md`](docs/security/SECURITY-AUDIT.md).

### Authorization is layered

Three roles — `tenant_admin`, `store_manager`, `store_staff` — enforced by **11 policies** plus named capability gates (`view-cost-data`, `view-reports`). Policies answer "may this user touch this record", gates answer "may this role do this kind of thing at all". Store-level isolation means a manager in one store cannot read or modify another store's orders, payments, or refunds.

See [ADR-002](docs/01-architecture/decisions/ADR-002-string-role-column.md) and [ADR-009](docs/01-architecture/decisions/ADR-009-capability-gates.md).

### Money has exactly one source of truth

`ledger_entries` is append-only. Customer balances and order payment status are **derived** from it, never stored. Only two sanctioned mutation paths exist (`adjustPayment`, `adjustOrderCharge`); every other correction appends a reversal.

This exists because a real bug shipped when three controllers each reimplemented `order.total - payments` and disagreed with one another. All financial math now lives in `LedgerService`.

See [ADR-003](docs/01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md), [ADR-006](docs/01-architecture/decisions/ADR-006-ledger-mutability-boundaries.md), and [`docs/07-business-rules/financial-calculations.md`](docs/07-business-rules/financial-calculations.md).

### Stock is an event log, not a number

`inventory.quantity` is the current count; `inventory_transactions` is the append-only history of how it got there. Every sale, restock, adjustment, and purchase receipt writes a row referencing what caused it.

Order items snapshot the product name and price at sale time, so editing a product never rewrites past invoices ([ADR-005](docs/01-architecture/decisions/ADR-005-snapshot-pricing.md)). Purchase receipts recalculate weighted-average cost ([ADR-008](docs/01-architecture/decisions/ADR-008-weighted-average-costing.md)).

### Layers are strict

```
Route → FormRequest (validation) → Controller (thin) → Service (business logic) → Model
                                         ↓
                                   API Resource (response shaping)
```

Controllers authorize and delegate. Business logic lives in services. All validation lives in FormRequests — 33 of them — and every response goes through an API Resource. See [`docs/01-architecture/backend-architecture.md`](docs/01-architecture/backend-architecture.md).

---

## Testing

```bash
php artisan test
```

**389 feature tests across 33 files**, run against **real MySQL rather than an in-memory SQLite database**, so foreign keys, transactions, and `STRICT_TRANS_TABLES` behave the way they will in production.

Coverage is concentrated where mistakes are expensive:

| Area | Tests |
|---|---|
| Role and permission matrix | `RoleTest` (47) |
| Ledger and financial correctness | `LedgerTest` (35) |
| Inventory movements | `InventoryTest` (24) |
| Timezone handling | `TimezoneTest` (23) |
| Discount rules and role ceilings | `DiscountTest` (23) |
| Cross-store data exposure | `CrossStoreExposureTest`, `CostDataVisibilityTest` |
| Monetary overflow boundaries | `MonetaryOverflowValidationTest` (12) |

Test conventions are in [`docs/04-engineering-standards/testing-strategy.md`](docs/04-engineering-standards/testing-strategy.md).

---

## Running it locally

**Requirements:** PHP 8.2+, MySQL 8+, Composer.

```bash
git clone https://github.com/AhmedNoaman602/multidukkan.git
cd multidukkan
composer install
cp .env.example .env
php artisan key:generate
```

Then edit `.env` — **`.env.example` currently defaults to SQLite, which this project does not support.** Set:

```
DB_CONNECTION=mysql
DB_DATABASE=multidukkan
DB_USERNAME=root
DB_PASSWORD=
```

Create the database, then:

```bash
php artisan migrate
php artisan db:seed --class=DemoSeeder
php artisan serve
```

Seed with `DemoSeeder` specifically. It builds a tenant with stores, warehouses, products, stock, customers, a supplier, and orders created through the real services — and, unlike the default `DatabaseSeeder`, it creates users you can actually log in with:

| Email | Password | Role |
|---|---|---|
| `noaman@multidukkan.com` | `password123` | `tenant_admin` |
| `ahmed@multidukkan.com` | `password123` | `store_manager` |

These are local development credentials committed to this repository. Never run `DemoSeeder` against anything but a local database.

### Verifying the database is configured correctly

Two diagnostic commands ship with the project. Both are read-only.

```bash
php artisan db:verify-sql-mode    # STRICT_TRANS_TABLES active on connection and server?
php artisan db:verify-engine      # every table on InnoDB?
```

Strict mode matters: without it MySQL silently truncates overflowing monetary values instead of rejecting them. See [`docs/DATABASE_GUIDE.md`](docs/DATABASE_GUIDE.md).

### Tests

Tests use a separate database named `multidukkan_test` (configured in `phpunit.xml`). Create it once:

```sql
CREATE DATABASE multidukkan_test;
```

---

## Documentation

This project is documented more heavily than most of its size, because it is also how I learn the domain. Start at [`docs/README.md`](docs/README.md).

| If you want | Read |
|---|---|
| A tour of the whole system | [`docs/DEVELOPER_GUIDE.md`](docs/DEVELOPER_GUIDE.md) |
| The invariants that must never break | [`docs/DOMAIN_RULES.md`](docs/DOMAIN_RULES.md) |
| Every table, column, and FK | [`docs/DATABASE_GUIDE.md`](docs/DATABASE_GUIDE.md) |
| Why a decision is the way it is | [9 ADRs](docs/01-architecture/decisions/) |
| Known rough edges, honestly ranked | [`docs/CODEBASE_NOTES.md`](docs/CODEBASE_NOTES.md) |
| The security review and what was fixed | [`docs/security/SECURITY-AUDIT.md`](docs/security/SECURITY-AUDIT.md) |

---

## Repository layout

```
app/
  Http/Controllers/Api/V1/   21 controllers
  Http/Requests/             33 FormRequests — all validation
  Http/Resources/            13 API Resources — all responses
  Services/                  business logic (Order, Ledger, Inventory, Payment, ...)
  Services/AI/               LLM integration
  Policies/                  11 authorization policies
  Models/Concerns/           ScopedToTenant global scope
  Rules/                     BelongsToTenant, OrderBelongsToCustomer
database/migrations/         55 migrations
tests/Feature/               33 test files, 389 tests
docs/                        engineering documentation
```

---

## Deployment

Production builds must:

- Run `composer install --no-dev --optimize-autoloader` — dev-only tooling (Telescope, Debugbar, Clockwork) must never reach a production `vendor/` directory.
- Set `TELESCOPE_ENABLED=false`, `APP_DEBUG=false`, and `APP_ENV=production` in the production `.env`.
- Ensure MySQL runs with `STRICT_TRANS_TABLES` and InnoDB — verify with the two commands above.

None of this is enforced by CI, because no pipeline exists in this repository yet. Treat it as a manual pre-deploy checklist. See finding C-01 in [`docs/security/SECURITY-AUDIT.md`](docs/security/SECURITY-AUDIT.md) for the incident behind the first two.

---

## License

No open-source license. This is a personal project published for reading and review, not for reuse.
