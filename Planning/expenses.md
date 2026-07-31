# MultiDukkan Expenses — Final Implementation Plan (V1)

## Context

MultiDukkan tracks money coming *in* (orders, payments, ledger) but has no way to record money going *out* (rent, salaries, utilities, etc.). This adds a deliberately boring, backend-only Expenses feature — a plain record of money leaving the business. No accounting/journal/approval/vendor machinery, and **no ledger integration** (an expense has no customer/supplier counterparty balance, so `LedgerService` correctly stays out of it). React frontend is a separate repo, out of scope.

Grounded decisions (confirmed against the codebase):
- Money serialized as `(float)` at the Resource boundary (matches `OrderResource`, `PurchaseOrderResource`).
- No active/inactive store concept exists — only validate store existence + tenant ownership.
- `store_id` uses `nullOnDelete()` — historical expenses survive store deletion as tenant-level NULL-store rows.
- Manager mutation requires **same tenant + same store + created_by = self**, enforced in the Policy itself.
- No `GET /expenses/{expense}` show endpoint, no `withTrashed()` on DELETE, no restore endpoint in V1.

---

# Phase 1 — Database Foundation

## Migration (`2026_07_16_114554_create_expenses_table.php` — already on disk, verify only)

- [ ] Confirm `store_id` FK uses `nullOnDelete()`
- [ ] Confirm `created_by` FK uses `nullOnDelete()`
- [ ] Confirm only the composite index `(tenant_id, expense_date)` exists (no redundant standalone indexes)
- [ ] Verify column types
  - [ ] category `string`
  - [ ] amount `decimal(10,2)`
  - [ ] description `string` nullable
  - [ ] expense_date `date`
  - [ ] softDeletes
- [ ] Run migration

## Verify

- [ ] Table created
- [ ] `store_id` / `created_by` FKs are `ON DELETE SET NULL`
- [ ] Composite index exists
- [ ] SoftDeletes enabled

### Commit

```bash
feat: add expenses database schema
```

---

# Phase 2 — Domain Layer

## Expense Model (already on disk, verify only)

- [ ] Fillable: tenant_id, store_id, category, amount, description, expense_date, created_by
- [ ] Casts: `expense_date` => `date`, `amount` => `decimal:2`
- [ ] `CATEGORIES` constant (SALARIES, RENT, UTILITIES, TRANSPORTATION, INTERNET, MAINTENANCE, SUPPLIES, MISCELLANEOUS)
- [ ] tenant / store / creator relations
- [ ] HasFactory + SoftDeletes

## Factory — `database/factories/ExpenseFactory.php`

- [ ] Mirror `SupplierPaymentFactory`
- [ ] Nullable FK defaults: tenant_id, store_id, created_by => null
- [ ] Default category `'RENT'` (non-MISC)
- [ ] Faker amount 100–5000
- [ ] Faker description sentence
- [ ] expense_date => `now()`

## Verify

- [ ] Factory creates a valid model

### Commit

```bash
feat: add expense factory
```

---

# Phase 3 — Validation

## StoreExpenseRequest

- [ ] `authorize()` returns `true` (auth via Policy)
- [ ] **No `created_by` field**
- [ ] `store_id`: `[]` when user has a store; else `nullable | exists:stores,id | BelongsToTenant`
- [ ] category: `required | Rule::in(Expense::CATEGORIES)`
- [ ] amount: `required | numeric | gt:0 | max:99999999.99 | decimal:0,2`
- [ ] description: `nullable | string | max:255`
- [ ] MISCELLANEOUS requires description (`Rule::requiredIf`)
- [ ] expense_date: `required | date | before_or_equal:today`

## UpdateExpenseRequest

- [ ] Same field rules as Store
- [ ] **Does not accept** `store_id` or `created_by` (immutable)
- [ ] MISCELLANEOUS-requires-description validates **effective state** via `withValidator()` (payload value else existing model value)

## Manual Validation Tests

- [ ] Future date rejected
- [ ] Zero / negative amount rejected
- [ ] Amount over `99999999.99` rejected (422, not 500)
- [ ] Invalid category rejected
- [ ] Description over 255 rejected
- [ ] MISC without description rejected on create
- [ ] MISC without description rejected on update-to-MISC

### Commit

```bash
feat: add expense form requests
```

---

# Phase 4 — Authorization

## ExpensePolicy — tenant check first, `store_staff` always false

- [ ] `viewAny`: admin or manager
- [ ] `create`: admin or manager
- [ ] `view`: same tenant AND (admin OR (manager AND `store_id === expense.store_id`))
- [ ] `update`: same tenant AND (admin OR (manager AND `store_id === expense.store_id` AND `id === expense.created_by`))
- [ ] `delete`: identical to `update`

## Authorization matrix

| Action | tenant_admin | store_manager | store_staff |
|---|---|---|---|
| index / create | ✅ | ✅ | ❌ |
| view | ✅ any in tenant | ✅ own-store only; ❌ tenant-wide NULL-store | ❌ |
| update / delete | ✅ any in tenant | ✅ same tenant + own store + created_by = self | ❌ |

Store Manager, precisely: can view own-store expenses; cannot view tenant-wide (NULL-store) expenses; can update/delete only own-store expenses they created; all require same tenant. A deleted store's expenses (`store_id → NULL`) become admin-only — intentional.

## Register Policy

- [ ] `Gate::policy(Expense::class, ExpensePolicy::class)` in `AppServiceProvider::boot()`

## Verify

- [ ] `store_staff` blocked everywhere
- [ ] Manager cannot act cross-store or on NULL-store expenses

### Commit

```bash
feat: add expense policy with store-scoped manager authorization
```

---

# Phase 5 — Business Logic

## ExpenseService (no constructor deps; all methods `DB::transaction`-wrapped)

- [ ] `createExpense(array $data, User $user): Expense`
  - [ ] tenant_id = `$user->tenant_id`
  - [ ] created_by = `$user->id` (never from payload)
  - [ ] store_id = `$user->store_id ?? ($data['store_id'] ?? null)`
- [ ] `updateExpense(Expense $expense, array $data): Expense`
  - [ ] updates category / amount / description / expense_date only
  - [ ] store_id + created_by immutable
- [ ] `deleteExpense(Expense $expense): void` — soft `$expense->delete()`

## Manual Verification

- [ ] Manager's foreign `store_id` / `created_by` in payload are overridden

### Commit

```bash
feat: add expense service
```

---

# Phase 6 — API Resource

## ExpenseResource

- [ ] `public static $wrap = null`
- [ ] id
- [ ] category (raw code only, no label)
- [ ] amount as `(float)` — matches Order/PurchaseOrder convention
- [ ] description
- [ ] expense_date `->toDateString()`
- [ ] store via `whenLoaded` (id + name)
- [ ] creator via `whenLoaded`, null-safe (id + name)
- [ ] created_at `->toDateTimeString()`

## Verify

- [ ] Response shape matches spec; no floating-point money issues

### Commit

```bash
feat: add expense resource
```

---

# Phase 7 — CRUD Endpoints

## Controller (constructor-inject ExpenseService)

- [ ] `store`: authorize `create` → `createExpense` → Resource, 201
- [ ] `update`: authorize `update` + belt-and-suspenders tenant re-check (403) → `updateExpense` → Resource
- [ ] `destroy`: authorize `delete` + tenant re-check → `deleteExpense` → `200 {message}`
- [ ] **No `show` method**

## Manual API Testing

- [ ] Create / update / delete happy paths
- [ ] Tenant re-check returns 403 on foreign tenant

### Commit

```bash
feat: add expense crud endpoints
```

---

# Phase 8 — Audit Logging

## ExpenseObserver (copy StoreObserver)

- [ ] `created`
- [ ] `updated` (`getChanges()` diff, exclude `updated_at`)
- [ ] `deleted` (fires on soft delete — free)
- [ ] No `deleting()` guard, no manual audit calls in service

## Register Observer

- [ ] `Expense::observe(ExpenseObserver::class)` in `AppServiceProvider::boot()`

## Verify

- [ ] Create / update / delete each produce exactly one `audit_logs` row

### Commit

```bash
feat: add expense audit observer
```

---

# Phase 9 — Listing API

## Base Query

- [ ] `where('tenant_id', $user->tenant_id)`
- [ ] `->when($user->store_id, ...)` — manager own-store; hides NULL-store
- [ ] `->with('store:id,name', 'creator:id,name')`

## Filters (V1 only)

- [ ] category
- [ ] date_from (`where('expense_date','>=',...)` — plain where, keeps index)
- [ ] date_to (`where('expense_date','<=',...)`)
- [ ] store_id — admin only (`$r->store_id && !$user->store_id`)

## Sorting

- [ ] `sort_by` whitelist `in:expense_date,amount`
- [ ] `sort_dir` whitelist `in:asc,desc`
- [ ] Default `expense_date desc, id desc`

## Stats

- [ ] `total_amount` = `(clone $query)->sum('amount')` before pagination, cast `(float)`

## Pagination

- [ ] `->paginate(25)`
- [ ] Response: `data` (Resource collection) + manual `meta` {current_page, last_page, total} + `stats`

## Verify

- [ ] Category + date-range filters return correct subsets
- [ ] `stats.total_amount` reflects filtered set

### Commit

```bash
feat: add expense listing api
```

---

# Phase 10 — Routes & Wiring

## Routes (in `auth:sanctum` group, near `/purchase-orders`)

- [ ] `GET /expenses`
- [ ] `POST /expenses`
- [ ] `PATCH /expenses/{expense}`
- [ ] `DELETE /expenses/{expense}` — **no `withTrashed()`**
- [ ] No `GET /expenses/{expense}`

## AppServiceProvider

- [ ] Policy registered
- [ ] Observer registered
- [ ] `use` imports added

## Verify

- [ ] Soft-deleted expense returns 404 via normal route binding

### Commit

```bash
feat: wire up expense routes
```

---

# Phase 11 — Automated Tests (`tests/Feature/ExpenseTest.php`)

PriceTierTest-style `setUp()`; typed props; factories; no hardcoded IDs; negative tests assert DB state.

## Validation

- [ ] Future date rejected
- [ ] Zero / negative amount rejected
- [ ] Amount over max rejected (422 not 500)
- [ ] Invalid category rejected
- [ ] Description over 255 rejected
- [ ] MISC without description rejected on create AND update-to-MISC

## Authorization (role)

- [ ] `store_staff` → 403 on index/store/update/destroy
- [ ] `tenant_admin` full access within tenant
- [ ] Manager can update/delete own-store, self-created expense

## Authorization (cross-store & tenant-wide)

- [ ] **Case A** — Store-A manager updates Store-B expense → 403, row completely unchanged
- [ ] **Case B** — manager updates AND deletes a NULL-store expense → 403 each, row unchanged
- [ ] **Case C** — manager creates expense (store_id = own); store deleted (clear warehouses/unpaid orders first) → store_id becomes NULL; expense survives as tenant-level row; original manager can no longer update/delete (403, unchanged); tenant admin still can
- [ ] Same-tenant-different-manager cannot update/delete another manager's same-store expense → 403, unchanged

## Store Scoping (index)

- [ ] Manager index excludes other stores' rows AND NULL-store rows
- [ ] Admin sees all

## Hardening

- [ ] Payload `created_by` ignored → row = authenticated user
- [ ] Manager `store_id` forced to own store even when payload differs

## Tenant Isolation

- [ ] Tenant A cannot view/update/delete tenant B's expense (403/404, row untouched)

## Soft Delete

- [ ] `destroy` → `assertSoftDeleted`; hidden from index; re-DELETE → 404

## Filtering

- [ ] Category + date-range filters correct; `stats.total_amount` correct

## Audit

- [ ] Create/update/delete each produce exactly one `audit_logs` row (update `changes` diff non-empty)

### Commit

```bash
test: add expense feature tests
```

---

# Phase 12 — Final Verification

## Automated

- [ ] `php artisan migrate`
- [ ] `php artisan test --filter=ExpenseTest` — all green
- [ ] `php artisan test` — full suite, no regressions

## Manual

- [ ] As manager, create expense sending foreign `store_id` + `created_by` → both overridden
- [ ] Delete the store (after clearing warehouses/unpaid orders) → expense survives with `store_id = NULL`; manager can no longer mutate it; admin can

---

# Explicitly Deferred from V1 (do NOT build now)

- [ ] `GET /expenses/{expense}` show endpoint
- [ ] Restore endpoint for soft-deleted expenses
- [ ] Filters: `search`, `created_by`, `amount_min`/`amount_max`, separate `month`/`year` params
- [ ] Richer audit-feed detail (amount/category in merged `/audit-log`)
- [ ] Dashboard `today()`/`thisMonth()` scopes
- [ ] Ledger integration (correct — no counterparty balance)
- [ ] PHP native enum for categories
