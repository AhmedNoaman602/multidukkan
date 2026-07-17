
---
# Phase 1 — Database Foundation

## Migration

- [ ] Review `create_expenses_table`
- [ ] Change `store_id` FK to `nullOnDelete()`
- [ ] Verify `created_by` uses `nullOnDelete()`
- [ ] Keep only the required composite index `(tenant_id, expense_date)`
- [ ] Verify column types
  - [ ] category
  - [ ] amount `decimal(10,2)`
  - [ ] description
  - [ ] expense_date
  - [ ] softDeletes
- [ ] Run migration

## Verify

- [ ] Table created
- [ ] Foreign keys correct
- [ ] Composite index exists
- [ ] SoftDeletes enabled

### Commit

```bash
feat: add expenses database schema
```

---

# Phase 2 — Domain Layer

## Expense Model

- [ ] Fillable
- [ ] Casts
- [ ] `CATEGORIES` constant
- [ ] tenant relation
- [ ] store relation
- [ ] creator relation
- [ ] HasFactory
- [ ] SoftDeletes

## Factory

- [ ] Create `ExpenseFactory`
- [ ] Default category
- [ ] Faker amount
- [ ] Faker description
- [ ] Faker date

## Verify

- [ ] Factory creates valid model

### Commit

```bash
feat: add expense model and factory
```

---

# Phase 3 — Validation

## StoreExpenseRequest

- [ ] `authorize()`
- [ ] Category validation
- [ ] Amount validation
- [ ] Decimal validation
- [ ] Amount max validation
- [ ] Description max length
- [ ] MISC requires description
- [ ] Expense date validation
- [ ] Cairo timezone validation
- [ ] Store ID tenant validation
- [ ] **DO NOT** accept `created_by`

## UpdateExpenseRequest

- [ ] Same validation
- [ ] Effective-state MISC validation
- [ ] Ignore `created_by`
- [ ] Ignore `tenant_id`
- [ ] Ignore `store_id` updates

## Manual Validation Tests

- [ ] Future date → `422`
- [ ] Invalid category → `422`
- [ ] Negative amount → `422`
- [ ] Zero amount → `422`
- [ ] Overflow amount → `422`
- [ ] Too many decimals → `422`
- [ ] Description >255 → `422`
- [ ] MISC without description → `422`
- [ ] Update to MISC without description → `422`

### Commit

```bash
feat: add expense validation
```

---

# Phase 4 — Authorization

## ExpensePolicy

- [ ] `viewAny`
- [ ] `view`
- [ ] `create`
- [ ] `update`
- [ ] `delete`

## Verify

Every method should:

- [ ] Check tenant first
- [ ] Allow tenant admin
- [ ] Restrict manager by ownership
- [ ] Deny store staff
- [ ] Handle orphaned creator safely

## Register Policy

- [ ] Register in `AppServiceProvider`

### Commit

```bash
feat: implement expense authorization
```

---

# Phase 5 — Business Logic

## ExpenseService

### `createExpense()`

- [ ] Wrap in transaction
- [ ] Force `tenant_id`
- [ ] Force `created_by`
- [ ] Force manager store
- [ ] Allow admin store selection

### `updateExpense()`

- [ ] Wrap in transaction
- [ ] Update editable fields only
- [ ] Keep `created_by` immutable
- [ ] Keep `store_id` immutable

### `deleteExpense()`

- [ ] Wrap in transaction
- [ ] Soft delete

## Manual Verification

- [ ] `tenant_id` forced
- [ ] `created_by` forced
- [ ] Manager store forced
- [ ] Immutable fields protected

### Commit

```bash
feat: implement expense service
```

---

# Phase 6 — API Resource

## ExpenseResource

- [ ] Cast amount to float
- [ ] Return raw category
- [ ] Null-safe creator
- [ ] Include store
- [ ] Include creator
- [ ] Include `created_at`
- [ ] Include `expense_date`

## Verify

- [ ] No presentation label returned
- [ ] JSON matches frontend contract

### Commit

```bash
feat: add expense resource
```

---

# Phase 7 — CRUD Endpoints

## Controller

### `store()`

- [ ] Authorize
- [ ] Use `validated()`
- [ ] Call service
- [ ] Return resource

### `show()`

- [ ] Authorize
- [ ] Tenant verification
- [ ] Return resource

### `update()`

- [ ] Authorize
- [ ] Tenant verification
- [ ] Call service
- [ ] Return resource

### `destroy()`

- [ ] Authorize
- [ ] Tenant verification
- [ ] Call service
- [ ] Return success response

## Manual API Testing

- [ ] Create
- [ ] Show
- [ ] Update
- [ ] Delete

### Commit

```bash
feat: implement expense CRUD endpoints
```

---

# Phase 8 — Audit Logging

## ExpenseObserver

- [ ] `created()`
- [ ] `updated()`
- [ ] `deleted()`

## Register Observer

- [ ] Register in `AppServiceProvider`

## Verify

- [ ] Create generates audit row
- [ ] Update generates audit row
- [ ] Delete generates audit row
- [ ] Exactly one audit row per action

### Commit

```bash
feat: add expense observer
```

---

# Phase 9 — Listing API

## Base Query

- [ ] Tenant scope
- [ ] Manager store scope
- [ ] Eager load `store`
- [ ] Eager load `creator`

## Filters

- [ ] Category
- [ ] Store
- [ ] Creator
- [ ] Search
- [ ] Amount min
- [ ] Amount max
- [ ] Exact date
- [ ] Date range
- [ ] Month / Year

## Sorting

- [ ] Whitelist fields
- [ ] Whitelist direction
- [ ] Default sort

## Stats

- [ ] `total_amount`

## Pagination

- [ ] `paginate(25)`
- [ ] Meta
- [ ] Stats

## Verify

- [ ] Date filters use `expense_date`
- [ ] Managers cannot see tenant-wide expenses
- [ ] Admin sees everything
- [ ] No N+1 queries
- [ ] Stable pagination

### Commit

```bash
feat: implement expense listing
```

---

# Phase 10 — Routes & Wiring

## Routes

- [ ] `GET /expenses`
- [ ] `POST /expenses`
- [ ] `GET /expenses/{expense}`
- [ ] `PATCH /expenses/{expense}`
- [ ] `DELETE /expenses/{expense}`

## AppServiceProvider

- [ ] Register Policy
- [ ] Register Observer

## Verify

- [ ] All endpoints registered
- [ ] Policies working
- [ ] Observer firing

### Commit

```bash
feat: wire expense feature
```

---

# Phase 11 — Automated Tests

## Validation

- [ ] Future date
- [ ] Amount validation
- [ ] Category validation
- [ ] Description validation
- [ ] Update-to-MISC validation

## Authorization

- [ ] Staff denied
- [ ] Manager owns expense
- [ ] Manager cannot edit others
- [ ] Admin allowed

## Store Scoping

- [ ] Manager only sees own store
- [ ] Admin sees all stores
- [ ] NULL-store expenses hidden from managers

## Tenant Isolation

- [ ] View
- [ ] Update
- [ ] Delete

## Service

- [ ] `created_by` forced
- [ ] `tenant_id` forced
- [ ] `store_id` forced

## Soft Delete

- [ ] `assertSoftDeleted()`

## Observer

- [ ] Create audit
- [ ] Update audit
- [ ] Delete audit

---

# Phase 12 — Final Verification

## Automated

- [ ] `php artisan migrate`
- [ ] `php artisan test --filter=ExpenseTest`
- [ ] `php artisan test`

## Manual

- [ ] Create expense
- [ ] Update expense
- [ ] Delete expense
- [ ] Manager permissions
- [ ] Admin permissions
- [ ] Cross-tenant access denied
- [ ] Audit logs generated
- [ ] Filters work
- [ ] Stats correct
- [ ] Pagination correct
- [ ] Delete a store and confirm expenses remain with `store_id = NULL`

---

# Definition of Done

The feature is complete when:

- [ ] Database schema is correct
- [ ] Validation prevents invalid data
- [ ] Authorization enforces tenant and role isolation
- [ ] Business rules live in `ExpenseService`
- [ ] CRUD endpoints function correctly
- [ ] Audit logging works through the observer
- [ ] Listing supports filtering, sorting, pagination, and stats
- [ ] Routes are registered
- [ ] Automated tests pass
- [ ] Manual verification passes
- [ ] Full project test suite passes