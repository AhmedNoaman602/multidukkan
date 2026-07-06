# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What is this project?

MultiDukkan is a multi-tenant SaaS application for small businesses to manage multi-store inventory and sales. Laravel REST API + MySQL, consumed by a separate React frontend (multidukkan-frontend).

## Current Status

- Phase 1 ✅ Complete — customers, products, orders, payments, ledger
- Phase 2 ✅ Complete — warehouses, inventory, inventory transactions
- Phase 3 🔓 In Progress — auth, roles, middleware, stock transfers

## Standing Engineering Rules

**Performance is a top priority, not an afterthought.** Fix N+1 queries, unpaginated full-dataset fetches, and unnecessary payload size immediately when identified. The business owner (Ahmed's father) has explicitly prioritized speed as the most important quality attribute of MultiDukkan.

**Financial/ledger calculations have exactly one source of truth: `LedgerService`.** Never recompute balance, debt, or "amount owed" manually in a controller (e.g. `order.total - payments`). If a balance is needed anywhere, call `LedgerService::getBalance()` (single customer) or `getBalancesForCustomers()` (batch). If the method doesn't exist yet, add it there. This rule exists because a real production bug shipped from three different controllers independently reimplementing the same math and disagreeing with each other.

## Architecture Rules — Non-Negotiable

- `tenant_id` on every table
- Never store `order.status` — calculate from payments
- Never store `order.total` — calculate from order_items
- Snapshot product name + price on order items at sale time
- Ledger is append-only — never edit, only add reversals
- Inventory transactions are append-only
- Business logic lives in Services — controllers stay thin
- FormRequests are gatekeepers — all validation lives there
- API Resources for all responses — never return raw models
- Always use `$request->validated()` — never `$request->all()`
- Type hint all service method parameters

## Stack

- Laravel (backend), MySQL
- Laravel Sanctum (token-based auth)
- PHPUnit for testing, Postman for API testing

## File Structure

- `app/Http/Controllers/Api/V1/` — all controllers
- `app/Http/Requests/` — all FormRequests
- `app/Http/Resources/` — all API Resources
- `app/Services/` — all business logic (LedgerService, OrderService, PaymentService, InventoryService)
- `app/Models/` — all Eloquent models
- `app/Rules/` — custom validation rules (`BelongsToTenant`, `OrderBelongsToCustomer`)
- `database/migrations/`, `database/factories/`
- `tests/Feature/` — all feature tests

## Database — Key Notes

- `inventory` table is named singular — `$table = 'inventory'` set on model
- `order_items` has `warehouse_id` (nullable) — no warehouse = skip stock check
- `customers` has `created_by_store_id` (nullable) — tracking only, not a restriction
- `ledger_entries` and `inventory_transactions` have no soft deletes — append only
- `users.store_id` nullable (null = tenant_admin)

## Auth & Roles

- Laravel Sanctum, token based
- Three roles (string column, not a roles table): `tenant_admin`, `store_manager`, `store_staff`
  - `tenant_admin`: `store_id = null`, full access
  - `store_manager`: `store_id` = their store, full access within store
  - `store_staff`: `store_id` = their store, limited access (orders + payments + read-only inventory/products + request transfers)

## Stock Transfer Rules

- Source store manager OR tenant_admin approves; `store_staff` can only request, never approve
- On approve: atomic — deduct source, add destination, log `TRANSFER_OUT` + `TRANSFER_IN`
- On reject: nothing moves
- Zero ledger entries — inventory only
- Statuses: `PENDING`, `APPROVED`, `REJECTED`, `COMPLETED`

## Locked Decisions — Never Revisit

- Sanctum for auth
- String role column (not a roles table)
- Source store approves transfers; store_staff cannot approve
- Products managed by tenant_admin only
- No ledger entries for transfers
- `warehouse_id` nullable on order_items
- Warehouse deletion blocked if stock > 0

## Test Rules

- Always use `RefreshDatabase`
- Always declare typed properties in `setUp()`
- Never hardcode IDs — always use factories
- Always use `/api/` prefix in routes
- Wrap single resource assertions with a `data` key
- Negative tests must verify DB state too
