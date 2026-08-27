# Product ↔ Suppliers on the Product Form — Implementation Plan

## Context

The Product form has a supplier search that does not work, and it models the relationship as **one** supplier. The backend has modelled it as many-to-many through `supplier_products` since Phase 2.5. This plan makes the Product form match the domain: search suppliers, select many, persist all of them, and never damage supplier-specific pivot data.

Nothing here changes the pivot's shape or the Supplier↔Product hardening finished in the previous step (authorization, `tenant_id`, tenant-safe write path, non-destructive partial updates, FormRequests).

---

## Root cause — four separate defects, found by inspection

### 1. The selected supplier is silently thrown away (the real bug)

`CreateProduct.jsx` and `EditProduct.jsx` both send `supplier_id`. It reaches the backend and then evaporates:

- `products` has **no `supplier_id` column** (confirmed via `php artisan db:table products`).
- `supplier_id` is **not in `Product::$fillable`**.

`ProductService::createProduct()` passes `'supplier_id' => $data['supplier_id'] ?? null` into `Product::create()`, and `ProductController::update()` passes `'supplier_id' => $request->supplier_id` into `$product->update()`. Eloquent's fillable guard drops unknown keys **without raising anything**, so both calls succeed and the supplier is never written — not to `products`, not to `supplier_products`.

`StoreProductRequest` even validates `'supplier_id' => 'nullable|exists:suppliers,id'`, which makes the field look supported. It is not. (`UpdateProductRequest` has no rule for it at all, yet `update()` reads `$request->supplier_id` directly — a `validated()` bypass.)

**Why the user sees "search doesn't work":** the search itself renders fine, but the selection never survives a save. On reload the field is empty again, which reads as a broken input.

### 2. Edit can never pre-select

`EditProduct.jsx:71` does `if (p.supplier_id) setSupplierId(p.supplier_id)`. `ProductResource` does not expose `supplier_id` (there is nothing to expose), so the condition is **always false**. Even if #1 were fixed, the form could not load its own saved state.

### 3. Search is blind past the 20th supplier

Both forms call `api.get('/suppliers')` once and filter **client-side** inside `SupplierSearchInput`. `SupplierController::index()` is hard-coded to `->paginate(20)` and — unlike `ProductController::index()` — has **no `per_page=all` support**. With more than 20 suppliers, typing a name that exists returns "No suppliers found".

This is invisible on the current demo seed (1 supplier), which is why #1 is the symptom being reported.

### 4. The UI models a single supplier

`useState(null)` holding a scalar id, `value` / `onSelect` scalar props, and a component that replaces the whole input with one selected row. The domain says many.

---

## Grounded decisions

**The Product form owns the relationship on save; `SupplierProductController` stays the per-link editor.**
The two are not competing systems. The Product form answers *"which suppliers sell this product"* (membership). `SupplierProductController` answers *"what are this supplier's terms for this product"* (`cost_price`, `is_preferred`, `notes`). Membership changes belong on the product save; terms stay where they are.

**Rejected: making the frontend loop over `POST/DELETE /suppliers/{s}/products/{p}`.**
It reuses the hardened endpoints and needs zero backend change, which is attractive. But on create the product must exist first, so it becomes create-then-N-attaches with no transaction — a failed 3rd attach leaves a saved product with 2 of 3 suppliers and no way for the user to tell. Membership is part of the product save and should commit or roll back with it.

**Rejected: a second `supplier_id` column, or copying supplier fields onto `products`.**
Explicitly out of bounds, and it would re-introduce the one-to-many model the pivot exists to prevent.

**The write goes through a model-level chokepoint, mirroring `Supplier::syncProducts()`.**
The previous step established that every pivot write stamps `tenant_id` from trusted server state, never the request. The product side gets the same treatment via `Product::syncSuppliers()`. One rule, two entry points — not a second system.

**`syncWithoutDetaching` + a scoped detach, not `sync()`.**
`sync()` computes the current set from a raw pivot query that ignores Supplier's global scopes. A **soft-deleted** supplier's link is therefore invisible to the form, absent from the submitted ids, and would be silently detached — destroying `last_purchase_price` / `last_purchased_at` history for a supplier the user cannot even see. Detaching only from `$this->suppliers()` (tenant-scoped, non-trashed) means the form can only remove what the form could show.

**Pivot metadata survives by construction.** `syncWithoutDetaching` writes `['tenant_id' => …]` for each id. For a row that already exists that is an `updateExistingPivot` of `tenant_id` to the value it already holds — `cost_price`, `last_purchase_price`, `last_purchased_at`, `is_preferred`, `notes` are never in the payload, so they are never touched. This is what satisfies the critical regression test.

**Omitted `supplier_ids` means "don't touch relationships".**
Only sync when the key is actually present in the request. A caller updating a price should not be able to wipe a product's suppliers by not mentioning them — the same omitted ≠ null rule the pivot endpoints already follow.

**Reading existing suppliers uses the endpoint that already exists.**
`GET /products/{product}/suppliers` is already routed, already authorized, and already returns pivot data via `ProductSupplierResource`. `ProductResource` is left completely alone — no API response shape changes.

**Cost fields are not touched.** Confirmed meanings before planning:
- `products.cost_price` — weighted-average cost, owned by `PurchaseOrderService`'s costing math.
- `supplier_products.cost_price` — this supplier's quoted price for this product.
- `last_purchase_price` / `last_purchased_at` — written only by `PurchaseOrderService` on receipt.

The Product form will send **no pivot cost at all**. Adding a supplier links it with `cost_price = null` until someone sets terms on the Supplier page. Any other choice would have the Product form overwriting supplier-specific commercial terms as a side effect of renaming a product.

**Existing single-select `SupplierSearchInput` is not converted.** `CreatePurchaseOrder.jsx` uses it, and a PO correctly has exactly one supplier. A new sibling component is added instead.

---

# Phase 1 — Backend

## 1. `SupplierController::index()` — support `per_page=all`

- [ ] `$perPage = $request->per_page === 'all' ? max(count($supplierIds), 1) : 20;`
- [ ] `$query->paginate($perPage)`

**Why this shape:** the endpoint returns a hand-built envelope (`data` / `meta` / `stats`), not a bare resource collection. Branching to `->get()` like `ProductController` does would mean duplicating that envelope or returning a different shape for one query param. Paginating by the full count returns everything on page 1 with `meta` and `stats` still correct and identical in structure. One line, no response-shape change for any existing caller.

## 2. `Product::syncSuppliers(array $supplierIds): void`

- [ ] `array_unique` + `intval` the incoming ids → duplicate selection cannot create duplicate pivot rows
- [ ] Build `[$id => ['tenant_id' => $this->tenant_id]]` — tenant from the model, never the request
- [ ] `$this->suppliers()->syncWithoutDetaching($payload)`
- [ ] `$this->suppliers()->pluck('suppliers.id')->diff($ids)` → `detach()` only those

Mirrors `Supplier::syncProducts()`. Rationale for each choice is in Grounded Decisions above.

## 3. FormRequests

- [ ] `StoreProductRequest`: **remove** the dead `supplier_id` rule, add
      `'supplier_ids' => ['sometimes','array']` and
      `'supplier_ids.*' => ['integer', new BelongsToTenant(Supplier::class, $tenantId)]`
- [ ] `UpdateProductRequest`: add the same two rules

**Why `BelongsToTenant` and not `exists:suppliers,id`:** `exists` would happily accept another tenant's supplier id. The project already has a rule for exactly this (`app/Rules/BelongsToTenant`, used in `UpdateProductRequest` for warehouses). Cross-tenant is rejected at the gate as a 422, before any pivot code runs.

## 4. `ProductService::createProduct()`

- [ ] Delete the phantom `'supplier_id' => $data['supplier_id'] ?? null`
- [ ] `$product->syncSuppliers($data['supplier_ids'] ?? [])` inside the existing `DB::transaction`

**Why inside the transaction:** membership commits or rolls back with the product itself. This is the whole reason for choosing a backend change over frontend attach-loops.

## 5. `ProductController::update()`

- [ ] Delete `'supplier_id' => $request->supplier_id`
- [ ] `if ($request->has('supplier_ids')) { $product->syncSuppliers($request->validated()['supplier_ids'] ?? []); }`

**Why `has()` and not `?? []`:** `?? []` would treat an omitted key as "remove every supplier".

## Not changing

- `ProductResource` — no new fields, no response shape change
- `SupplierProductController`, the pivot migration, `Supplier::syncProducts()`
- Anything in the authorization layer

---

# Phase 2 — Frontend

## 6. New `src/components/SupplierMultiSelectInput.jsx`

Built from `SupplierSearchInput`'s markup so it looks native to the app — same input, same dropdown, same Browse modal, same Tailwind classes.

- [ ] Props: `suppliers`, `value` (array of ids), `onChange(ids)`, `placeholder`
- [ ] Selected suppliers render as removable chips above the input
- [ ] Already-selected suppliers are filtered out of the dropdown and Browse list → no duplicates possible
- [ ] Keyboard: ArrowUp/Down/Enter/Escape, matching the existing component
- [ ] Empty supplier list and zero-result search both render their existing copy
- [ ] Input is **not** replaced on selection — it stays available for the next pick

- [ ] New i18n keys in `src/i18n/en/search.js` and `src/i18n/ar/search.js` under `search.supplier`

## 7. `CreateProduct.jsx` / `EditProduct.jsx`

- [ ] `supplierIds` array state instead of scalar `supplierId`
- [ ] Fetch `/suppliers?per_page=all`
- [ ] Send `supplier_ids`, drop `supplier_id`
- [ ] `EditProduct`: add `GET /products/{id}/suppliers` to the existing `Promise.all`, seed state from it, delete the dead `if (p.supplier_id)` branch

## 8. `CreatePurchaseOrder.jsx` — one-line fix, flagged

- [ ] `/suppliers` → `/suppliers?per_page=all`

Strictly outside "the Product form", but it is the same defect #3 with the same one-word fix, and leaving a knowingly broken supplier search in place is worse than the tiny scope creep. Calling it out rather than burying it. **No** other change to that page.

---

# Phase 3 — Tests

Backend, `tests/Feature/` with `RefreshDatabase` and factories, following existing conventions:

- [ ] create product with multiple suppliers → all pivot rows exist, correct `tenant_id`
- [ ] edit product adding a supplier → existing suppliers preserved
- [ ] edit product removing one supplier → only that one detached
- [ ] duplicate ids in `supplier_ids` → one pivot row
- [ ] cross-tenant supplier id → 422, no pivot row
- [ ] omitted `supplier_ids` on update → suppliers untouched
- [ ] **critical regression:** A@100 + B@120, update an unrelated product field → both still linked, both `cost_price` intact
- [ ] soft-deleted supplier's link is not silently detached
- [ ] `/suppliers?per_page=all` returns more than 20

Frontend: the repo has **no test runner** (`package.json` scripts are `dev`/`build`/`lint`/`preview`, no vitest/jest). Per the brief, no test infrastructure will be introduced — verification is `npm run build`, ESLint on changed files, and a manual browser pass through the 11 listed steps.

---

# Verification

1. `php artisan test` (full backend suite)
2. `npm run build`
3. `npx eslint` on changed frontend files
4. Manual browser flow: search → multi-select → save → reload → add → remove → confirm pivot data intact → cross-tenant
5. `git diff` in both repos

---

# Known issues this plan deliberately does not fix

- `ProductController::update()` reads `$request->name`, `$request->price`, … directly instead of `$request->validated()`, against the standing project rule. Pre-existing across the whole method; fixing it is a separate refactor.
- `EditSupplier.jsx` and `SupplierBalance.jsx` both manage supplier↔product links with different UIs. Consolidation is already queued as its own step.
- `SupplierBalance.jsx`'s bulk-attach cart prefills pivot `cost_price` from the **product's** weighted-average cost. Flagged in the previous step, still open.
