# API Conventions

Everything under `/api`, auth via `Authorization: Bearer <sanctum-token>` except `POST /login`, `POST /register`.

## URL shape

- Plural resources: `/orders`, `/customers`, `/purchase-orders` (kebab-case for multi-word).
- Sub-resources for owned collections and computed views: `/customers/{customer}/balance`, `/customers/{customer}/ledger`, `/orders/{order}/items`, `/suppliers/{supplier}/products`.
- Actions that aren't CRUD are verbs as sub-paths: `/inventory/{inventory}/adjust`, `/payments/auto`, `/customers/{customer}/refund`, `/customers/{customer}/credit`. Keep these rare and named after the business action.
- Deletes on soft-deleted resources bind `->withTrashed()` so a double-delete 404s meaningfully rather than silently.

## HTTP verbs

`GET` list/show, `POST` create/action, `PUT` full update (stores, customers, products, warehouses, suppliers), `PATCH` partial update (orders, payments, order items). Follow the existing per-resource choice; don't mix PUT and PATCH on one resource.

## Response shape

- All success responses via API Resources → payloads under `data` (Laravel default). Collections paginate — **unpaginated full-table fetches are a performance bug**, per the standing speed requirement.
- Validation errors: Laravel standard `422` with `errors` keyed by field. Business-rule rejections also use 422 via `ValidationException::withMessages` (e.g. insufficient stock, refund cap) so the frontend renders them identically to field errors.
- Tenant misses should look like nonexistence (`404`) rather than `403` where possible — don't confirm a foreign resource exists.

## Resource design rules

- Never expose: `tenant_id`, internal flags that have UI meaning only (`is_auto_reversible` is borderline — it's exposed logic, document if surfaced).
- Computed money fields on resources come from `LedgerService` / model helpers (`isSettled`, `settledAmount`) — never recomputed inline in the Resource.
- Batch computed fields for collections (resource collections receive pre-fetched balance maps; see `getBalancesForCustomers` usage) — a Resource that queries per-item is an N+1.

## Versioning stance

Controllers live in `Api\V1` but URLs are **not** `/v1/`-prefixed. Locked stance until a real V2 need appears: keep the namespace, don't add the prefix retroactively (would break the deployed frontend). If V2 happens, prefix only V2 (`/api/v2/...`) and leave V1 unprefixed as grandfathered.

---
**Related documents**: [Backend Architecture](../01-architecture/backend-architecture.md), [Coding Standards](coding-standards.md).
**Future improvements**: generate an OpenAPI spec (scramble or manual) so the frontend and future mobile apps have a contract; document pagination meta shape.
**Open questions**: `POST customers/{customer}/refund` lives on CustomerController while credit/balance live on LedgerEntryController — consolidate ownership when next touched.
**Last review checklist**: [ ] conventions match `routes/api.php`, [ ] versioning stance still holds. Last reviewed: 2026-07-08.
