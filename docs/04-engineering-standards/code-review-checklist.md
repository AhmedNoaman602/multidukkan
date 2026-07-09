# Code Review Checklist

Run this before **every pull request merge** and before accepting **every AI-generated change**. It is the enforcement layer for the ADRs, domain docs, and the [AI Collaboration Guide](../09-ai-collaboration/ai-collaboration-guide.md) — its job is to stop architectural drift one diff at a time.

**How to use**: answer every item **Yes / No / N/A**.
- **Any "No" blocks the merge** until fixed, or explicitly waived by the project owner *in writing on the PR* (a waiver on a 🔴 item additionally requires a new/updated ADR).
- **"N/A" must be justifiable in one sentence** if challenged ("this PR touches no money paths").
- Items marked 🔴 are the incident-born rules — the ones that already caused, or nearly caused, production damage.
- Sections marked **[FE]** apply to `multidukkan-frontend` PRs; skip them (N/A) for backend PRs, and vice versa.
- Reviewers (human or AI): the *Verify* line under each item is your auto-review question — literally ask it of the diff before ticking.

---

## 1. Architecture

- [ ] 🔴 **The change contradicts no Accepted ADR.**
  *Why*: ADRs record decisions that were expensive to make and are expensive to reverse ([index](../01-architecture/decisions/README.md)). Silent contradiction = drift.
  *Mistake*: "improving" stored `orders.total` back to derived (ADR-004); converting the ledger's two edit paths to append-reversals (ADR-006); adding a roles table (ADR-002).
  *Verify*: for each ADR touched by this diff's subject area, does the diff comply? If not, is there a superseding ADR in this same PR?

- [ ] **Business logic lives in a Service; the controller only validates, delegates, and shapes output.**
  *Why*: services are callable from jobs/commands/tests; controllers are HTTP adapters ([backend-architecture.md](../01-architecture/backend-architecture.md)).
  *Mistake*: loops with `Model::create` in a controller; conditionals encoding business rules in `OrderController` instead of `OrderService`.
  *Verify*: could this controller method be re-implemented as a console command by calling the same service method? If not, logic leaked.

- [ ] 🔴 **Every operation touching ≥2 of {orders, payments, ledger_entries, inventory} is inside one `DB::transaction`.**
  *Why*: a half-committed sale (stock deducted, no ledger charge) is data corruption that no later code can detect.
  *Mistake*: the known `adjustItem` gap — stock moved, then an unwrapped ledger update that can fail independently.
  *Verify*: trace every write in the changed code path; are all of them inside the same transaction closure?

- [ ] **Order status is derived, never stored; `orders.total` is written only via `LedgerService::adjustOrderCharge` after creation.**
  *Why*: ADR-004's write-funnel is what makes a stored total safe.
  *Mistake*: `$order->update(['total' => ...])` anywhere outside `OrderService::createOrder`/`adjustOrderCharge`; adding a `status` column "for filtering".
  *Verify*: grep the diff for `'total'` and `status` writes on orders.

- [ ] **Snapshot semantics preserved: historical display reads snapshot columns, never live joins.**
  *Why*: ADR-005 — invoices must survive product/customer edits and deletions.
  *Mistake*: an invoice endpoint rendering `$item->product->name` instead of `$item->product_name`.
  *Verify*: does any changed read path join through `product_id`/`customer_id` for name or price display?

## 2. Folder placement

- [ ] **Every new file is in its canonical home**: controllers → `app/Http/Controllers/Api/V1/`, FormRequests → `app/Http/Requests/`, Resources → `app/Http/Resources/`, business logic → `app/Services/`, validation rules → `app/Rules/`, observers → `app/Observers/`, feature tests → `tests/Feature/`.
  *Why*: a predictable tree is what lets a session (human or AI) find the one existing implementation instead of writing a second one.
  *Mistake*: a `Helpers/` or `Traits/` grab-bag directory; an action class pattern imported from another project; controllers outside `Api\V1`.
  *Verify*: `git diff --stat` — does any new path introduce a directory not listed in [backend-architecture.md](../01-architecture/backend-architecture.md)?

- [ ] **No stray files committed** (debug scripts, dumps, scratch files).
  *Why*: `debug_balance.php` in repo root is the cautionary example — scratch work must not ship.
  *Mistake*: committing tinker experiments, `.http` scratch files, storage debug output.
  *Verify*: does every file in the diff earn its place in production?

## 3. Dependency rules

- [ ] **Layer directions respected**: controllers don't call controllers; services don't touch `Request`/Resources; models don't call services; Resources never mutate.
  *Why*: the dependency table in [backend-architecture.md](../01-architecture/backend-architecture.md) — violations make code untestable and un-reusable.
  *Mistake*: a Resource that lazily triggers a write; a model event doing money movement (money belongs in transaction-controlled services).
  *Verify*: for each new `use` statement, does the import point "downward" per the layer table?

- [ ] **New/changed service methods receive `User $user` as a parameter instead of calling `auth()`.**
  *Why*: `auth()` couples services to HTTP; the `PaymentService` pattern is the standard. (Legacy violations in `OrderService`/`PurchaseOrderService` are grandfathered, not precedent.)
  *Mistake*: copying `auth()->user()` from `OrderService` into a new service "for consistency".
  *Verify*: grep the diff for `auth()` inside `app/Services/`.

- [ ] **Observers contain only mechanical side-effects** (cascades, code generation) — no money, no stock.
  *Why*: observers fire implicitly and escape transaction reasoning.
  *Mistake*: posting a ledger entry from a model event.
  *Verify*: does any changed observer write to ledger_entries, payments, or inventory?

## 4. Imports

- [ ] 🔴 **Exception classes are imported with full namespace — especially `Illuminate\Validation\ValidationException`.**
  *Why*: a live defect shipped as `throw new \ValidationException(...)` (root namespace, class doesn't exist → fatal 500 instead of a 422).
  *Mistake*: relying on an IDE-less session "remembering" the class; catching `\Exception` broadly to compensate.
  *Verify*: for every `throw`/`catch` in the diff, does the class resolve? Is there a `use` statement for it?

- [ ] **No facade/DB usage smuggling business math into the wrong layer.**
  *Why*: `DB::raw` money sums outside services/scopes recreate the founding ledger bug in SQL form.
  *Mistake*: a controller adding `DB::raw('SUM(amount - refunded_amount)')` inline instead of using `scopeWhereUnpaid`/`LedgerService`.
  *Verify*: grep the diff for `DB::raw` — is each occurrence in a service or model scope, and is it non-financial or delegated?

## 5. React **[FE]**

- [ ] 🔴 **No client-side money math.** Components display balances/totals/status exactly as the API returns them.
  *Why*: the frontend edition of ADR-003 — a client recomputing `total - payments` reintroduces the founding bug beyond the backend's reach.
  *Mistake*: summing order items in JS to show a total; deriving "paid" status from a payments array client-side.
  *Verify*: search the diff for arithmetic on `amount`, `total`, `balance`, `price` fields — is any of it producing a *displayed financial value* rather than a UI convenience (e.g. cart preview clearly labeled as estimate)?

- [ ] **Server data is fetched via React Query hooks, never `useEffect` + fetch.**
  *Why*: one caching/invalidation system; two systems guarantee stale-data bugs.
  *Mistake*: an ad-hoc `useEffect` fetch for "just this one dropdown".
  *Verify*: any new `useEffect` with a fetch/axios call inside?

- [ ] **Forms render Laravel's 422 `errors` shape per field, including business-rule 422s** (insufficient stock, refund caps).
  *Why*: the backend deliberately routes business rejections through the same 422 shape so the UI handles them uniformly ([api-conventions.md](api-conventions.md)).
  *Mistake*: showing business-rule errors only as a toast while field errors render inline — Arabic merchant users miss them.
  *Verify*: does the form map `errors.<field>` AND display non-field keys (e.g. `message`, `order`)?

- [ ] **Money-mutating forms submit pessimistically** (disable, await server, then update).
  *Why*: optimistic UI on payments/orders shows the merchant money states that may roll back — trust-destroying.
  *Mistake*: optimistic cache update on payment creation.
  *Verify*: any `onMutate` optimistic update touching money entities?

## 6. React Query **[FE]**

- [ ] **Query keys follow the established convention and are defined centrally, not inline string-built.**
  *Why*: invalidation only works if keys are predictable ([05-frontend stub](../05-frontend/README.md)).
  *Mistake*: `['orders-list-2']` one-offs; keys missing the filter params that affect results.
  *Verify*: is every new key in the shared key factory/constants module?

- [ ] 🔴 **Every mutation invalidates the full side-effect set, derived from the backend's documented ledger effects.**
  *Why*: creating a payment changes the order, the customer balance, unpaid lists, and the dashboard — the backend docs ([order-lifecycle.md](../07-business-rules/order-lifecycle.md)) are the invalidation map. Missing one shows a merchant a stale debt.
  *Mistake*: invalidating only `['payments']` after a payment; forgetting the dashboard after order cancel.
  *Verify*: list this mutation's backend side-effects from the lifecycle doc — is each corresponding query key invalidated?

- [ ] **No server data copied into local/global state.**
  *Why*: the copy goes stale the moment React Query refetches.
  *Mistake*: `useState(data)` to make a fetched list "editable"; storing the customer list in a zustand store.
  *Verify*: any `useState`/store initialization from query `data`?

## 7. Routing

- [ ] **Backend: new routes follow [api-conventions.md](api-conventions.md)** — plural kebab-case resources, business-verb sub-paths only for non-CRUD, `->withTrashed()` on soft-delete destroys, correct PUT-vs-PATCH per existing resource choice.
  *Why*: the frontend and future mobile apps hardcode these shapes.
  *Mistake*: `/getOrders`; mixing PUT and PATCH on one resource; forgetting `withTrashed()` so double-delete 500s.
  *Verify*: diff on `routes/api.php` — does each new line match an existing pattern?

- [ ] **[FE] Routes live in the central route config; protected screens sit behind the auth guard; URL params (not state) carry entity identity.**
  *Why*: deep-linking an order detail must work from a shared WhatsApp link — merchants share URLs.
  *Mistake*: navigating with entity objects in router state so refresh breaks the page.
  *Verify*: does refreshing a new screen with its URL alone still render it?

## 8. State management **[FE]**

- [ ] **Server state in React Query; UI state in component state; no new global store without a written justification in the PR.**
  *Why*: the [05-frontend stub](../05-frontend/README.md) permits at most a POS-cart store — every additional store is drift toward an unmaintainable state soup.
  *Mistake*: a global store for "current customer" that duplicates the route param.
  *Verify*: does the diff add a store/context? Is its justification in the PR description?

## 9. API layer

- [ ] 🔴 **Backend: every response goes through an API Resource — no raw models/collections.**
  *Why*: raw models leak every column a future migration adds (`tenant_id`, internal flags).
  *Mistake*: `return $order;` or `return response()->json($orders)` for "simple" endpoints.
  *Verify*: does every changed controller return line wrap in a Resource?

- [ ] **Backend: collection endpoints paginate; computed money fields on collections are batch-fetched and passed in, never queried per-item in the Resource.**
  *Why*: the speed requirement + the N+1 that already shipped (fixed in `2abffa7`).
  *Mistake*: `LedgerService::getBalance()` inside `CustomerResource::toArray()`.
  *Verify*: does any Resource in the diff execute a query per instance?

- [ ] **Backend: tenant misses return 404, not 403, where feasible.**
  *Why*: don't confirm a foreign resource exists ([api-conventions.md](api-conventions.md)).
  *Verify*: what does the new endpoint return for another tenant's ID?

- [ ] **[FE] All requests go through the single API client; `data`-unwrapping stays in one place; no component imports axios directly.**
  *Why*: token attachment, error normalization, and unwrapping must have exactly one implementation.
  *Verify*: grep the diff for `axios`/`fetch(` outside the API layer.

## 10. Business logic

- [ ] 🔴 **No balance/debt/credit/owed math outside `LedgerService`.**
  *Why*: [ADR-003](../01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md) — the founding production bug. The complete formula table lives in [financial-calculations.md](../07-business-rules/financial-calculations.md); if a number you need isn't there, the fix is a new `LedgerService` method, not inline math.
  *Mistake*: `order.total - payments` anywhere; re-deriving credit as a sum in a report query.
  *Verify*: does the diff compute any financial figure? Is the computation inside `LedgerService` (or `Order`'s three sanctioned helpers)?

- [ ] 🔴 **No `update()`/`delete()` on `LedgerEntry` outside `adjustPayment`/`adjustOrderCharge`; corrections otherwise append** (`REVERSAL`, `REFUND`, `CREDIT_APPLY`).
  *Why*: [ADR-006](../01-architecture/decisions/ADR-006-ledger-mutability-boundaries.md) — auditability with exactly two sanctioned exceptions.
  *Verify*: grep the diff for `LedgerEntry::` and `ledger_entries` writes.

- [ ] 🔴 **No `inventory.quantity` mutation outside `InventoryService`; every mutation logs an `inventory_transactions` row; quantities in base units.**
  *Why*: state and history must move together or stock counts become unfalsifiable.
  *Mistake*: `$inventory->decrement()` inline; the known `Product::booted()` violation is grandfathered, not precedent.
  *Verify*: grep the diff for `increment('quantity'`/`decrement('quantity'`.

- [ ] **Unit conversion handled at every touched site — and if conversion semantics changed, all three sites changed** (`OrderService`, `PurchaseOrderService`, `InventoryService::adjustStock`).
  *Why*: [costing-and-inventory-rules.md Rule 1](../07-business-rules/costing-and-inventory-rules.md) — a partial change corrupts stock silently.
  *Mistake*: handling only `unit_type = 'base'` in new stock code; removing the `&& $product->conversion_factor` null guard.
  *Verify*: does the changed code path receive a `unit_type`? Is the secondary branch present and guarded?

- [ ] **Both stock-movement branches handled: warehoused (check → move → log) and null-warehouse (skip entirely).**
  *Why*: [ADR-007](../01-architecture/decisions/ADR-007-nullable-warehouse-on-line-items.md) — null warehouse is a first-class case, not an edge.
  *Verify*: what happens in the new code when `warehouse_id` is null?

- [ ] **Established allocation conventions respected: FIFO everywhere money distributes; credit auto-consumes before cash at order creation.**
  *Why*: [financial-calculations.md](../07-business-rules/financial-calculations.md) — merchants' mental model is "oldest debt settles first"; two allocation strategies in one system is a support nightmare.
  *Verify*: does new distribution logic order by `created_at asc` (orders) / `id asc` (payments)?

- [ ] **Merchant escape hatches survive**: `manual_total`, unit-price overrides, backdated `order_date`, walk-in customers.
  *Why*: flexibility is a feature ([philosophy](../00-overview/product-and-engineering-philosophy.md)); "tightening validation" that blocks them is a regression.
  *Verify*: does any new validation rule reject an input the escape hatches rely on?

## 11. Error handling

- [ ] **User-facing business rejections use `ValidationException::withMessages([...])` → 422, with Arabic messages where the audience is the merchant.**
  *Why*: the frontend renders all 422s uniformly; merchants read Arabic ([coding-standards.md](coding-standards.md)).
  *Mistake*: returning 500s or bare `abort(400)` for business rules; translating existing Arabic messages to English.
  *Verify*: every new business-rule rejection — what status code and shape does it produce?

- [ ] **Tenant mismatches fail loudly** (throw when scoped lookups come back short), never silently filter.
  *Why*: silent filtering hides both attacks and frontend bugs (the `PurchaseOrderService` guard is the pattern).
  *Verify*: if an input ID belongs to another tenant, does the new code throw or quietly drop it?

- [ ] **No broad `try/catch` swallowing exceptions inside transactions.**
  *Why*: catching inside `DB::transaction` and continuing commits partial state — worse than the crash.
  *Verify*: any `catch` block in the diff that doesn't rethrow or fully abort?

## 12. Performance

- [ ] 🔴 **No queries inside loops; related rows prefetched (`whereIn` + `keyBy`); collection balances via the batch methods** (`getBalancesForCustomers`/`getBalancesForSuppliers`).
  *Why*: speed is the owner's #1 quality attribute; the per-row-balance N+1 already shipped once.
  *Verify*: walk each loop in the diff — any `::find`, `->first()`, relation lazy-load, or service call per iteration?

- [ ] **Every new list endpoint paginates; no unpaginated full-table fetch anywhere.**
  *Why*: standing rule from CLAUDE.md; full fetches degrade linearly with the business's success.
  *Verify*: does each new `->get()` on a user-facing list have a `paginate()` instead?

- [ ] **Eager loading (`with(...)`) matches what the Resource actually reads.**
  *Mistake*: Resource reads `items.product` but the controller loads only `items` — N+1 detonates in the Resource where nobody looks.
  *Verify*: list the relations the Resource touches; are all in the `with()`?

- [ ] **New hot-path columns used in WHERE/ORDER are indexed** (tenant indexes precedent: migration `2026_06_18`).
  *Verify*: what does `EXPLAIN` say for the new query at 100k rows?

## 13. Accessibility **[FE]**

- [ ] **RTL renders correctly** for every new screen/component (Arabic is the primary UI language).
  *Mistake*: hardcoded `left:`/`ml-` where logical properties/`ms-` are needed; icons that don't mirror.
  *Verify*: was the screen viewed in RTL before merge?

- [ ] **POS-critical flows are keyboard-operable** (counter staff work keyboard-first) and touch targets meet size minimums.
  *Verify*: can a sale be completed without a mouse?

- [ ] **Money states don't rely on color alone** (debt/paid also differ by sign/label/icon).
  *Why*: color-blind staff misreading debt is a real financial error.
  *Verify*: strip the color — is the state still distinguishable?

## 14. Security

- [ ] 🔴 **Every new/changed query on a business table scopes `tenant_id` explicitly.**
  *Why*: there is no global scope; every unscoped query is a cross-tenant data leak.
  *Mistake*: `Order::findOrFail($id)` without a tenant clause (note: `OrderService::createOrder`'s customer lookup is a known grandfathered gap — don't copy it).
  *Verify*: for each query in the diff, where is the `tenant_id` constraint?

- [ ] **Foreign IDs in request input validated with `BelongsToTenant` (or equivalent scoped rule) in the FormRequest.**
  *Verify*: every `*_id` field in new validation rules — which rule pins it to the tenant?

- [ ] 🔴 **`$request->validated()` only — never `$request->all()`; `$fillable` reviewed for any new mass-assigned column.**
  *Why*: `all()` + fillable = mass-assignment injection.
  *Verify*: grep the diff for `->all()`.

- [ ] **New routes are inside the `auth:sanctum` group; role checks (Phase 3+) enforced server-side, never UI-only.**
  *Verify*: is any new route outside the middleware group? Intentional?

- [ ] **No secrets, tokens, or production data in the diff** (code, tests, fixtures, docs).
  *Verify*: scan the diff for keys, real phone numbers, real balances.

## 15. Reusability

- [ ] **Existing implementation checked before writing a new one** — `LedgerService` methods, `Order` helpers, scopes (`cashOnly`, `creditOnly`, `whereUnpaid`), `BelongsToTenant`, existing Resources.
  *Why*: the duplicate-implementation failure mode is this project's founding bug; duplication of *any* rule recreates it.
  *Mistake*: writing a second "unpaid orders" query instead of `scopeWhereUnpaid`; a second refund path beside `issueRefund`.
  *Verify*: for each new method, name the search you did for an existing equivalent.

- [ ] **Deliberately duplicated logic (unit conversion ×3) either left consistent or extracted — never a fourth copy.**
  *Verify*: does the diff add another conversion site instead of reusing/extracting?

## 16. Naming

- [ ] **Names follow the [coding-standards.md](coding-standards.md) table** (`*Controller` in `Api\V1`, `*Service`, `Store*/Update*Request`, `*Resource`).
  *Verify*: does each new class name pattern-match its directory's existing files?

- [ ] **New ledger entry types are SCREAMING_SNAKE and registered in BOTH `LedgerEntry::TYPES` and a DB enum migration; new inventory types get `TYPE_*` constants.**
  *Why*: a type in one place but not the other fails at insert time in production.
  *Verify*: both files in the diff?

- [ ] **Money variables state their stage in the math** (`$chargeAmount`, `$appliedAmount`, `$leftover`) — no `$amt`/`$val`/`$temp`.
  *Why*: ambiguity in money code is where reviewer attention dies.
  *Verify*: can you tell each money variable's meaning without reading its assignment?

## 17. Styling

- [ ] **Backend: new code matches the surrounding file's style and comment density; stale comments removed when the code they describe changes.**
  *Why*: the stale `BUG:` comment in `PurchaseOrderService` misled reviewers for weeks.
  *Verify*: any comment in the diff describing behavior the diff itself changed?

- [ ] **[FE] Styles use Tailwind theme tokens — no ad-hoc hex values or magic pixel sizes; money display uses the shared primitive** (2dp, EGP, sign-colored).
  *Why*: [design-system stub](../02-design-system/README.md) — duplicated money-display components are the frontend ledger bug.
  *Verify*: grep the diff for `#` colors and `px` literals outside the theme config.

## 18. Testing

- [ ] **The mechanical five hold: `RefreshDatabase`; typed props in `setUp()`; factories, never hardcoded IDs; `/api/` prefix; single resources asserted under `data`.**
  *Verify*: scan each new test class against the five.

- [ ] 🔴 **Negative tests assert DB state, not just status codes** (`assertDatabaseMissing`/`assertDatabaseCount` proving rollback).
  *Why*: a 422 with a half-written order is worse than a 500 — only DB assertions catch it.
  *Verify*: every failure-path test — what does it assert beyond the status?

- [ ] 🔴 **Every new endpoint has a tenant-isolation negative test** (foreign tenant's resource → 404/403 AND zero rows changed).
  *Why*: the cheapest security net we have ([testing-strategy.md](testing-strategy.md)).
  *Verify*: name the test.

- [ ] **Money changes come with balance assertions through `LedgerService`** — the test must not re-sum payments itself.
  *Why*: a test re-implementing the formula re-implements the bug ADR-003 prevents.
  *Verify*: grep new tests for manual payment summing.

- [ ] **Unit-conversion paths tested when touched** (secondary-unit line: base-unit stock delta, converted price, correct total).
  *Verify*: is there a `unit_type => 'secondary'` case in the new tests?

## 19. Documentation

- [ ] 🔴 **Behavior changes update their docs in the same PR** — money math → [financial-calculations.md](../07-business-rules/financial-calculations.md); lifecycle → [order-lifecycle.md](../07-business-rules/order-lifecycle.md); schema → the relevant `docs/06-domain/` file. A money-math PR without a docs diff is incomplete by definition.
  *Verify*: does the diff touch `app/Services/` money/stock code without touching `docs/`?

- [ ] **Decision-level changes ship an ADR; drive-by contradictions don't merge.**
  *Verify*: did this PR change anything the [ADR index](../01-architecture/decisions/README.md) covers?

- [ ] **AI-guide hygiene: fixed defects removed from the [known-defects list](../09-ai-collaboration/ai-collaboration-guide.md); new AI-made mistakes appended to the mistakes list; divergence table updated if a documented divergence was resolved.**
  *Why*: the guide is only as protective as it is current.
  *Verify*: does this PR fix anything that list mentions?

- [ ] **CLAUDE.md still true after this PR** (status, rules, key notes).
  *Verify*: skim it — 30 seconds.

## 20. Future scalability

- [ ] 🔴 **Every new table has `tenant_id` + supporting index, and history tables get no soft-delete columns.**
  *Why*: retrofitting tenancy is a migration nightmare; deletable history isn't history.
  *Verify*: read the new migration — both present?

- [ ] **Migrations are additive and reversible; no editing or deleting shipped migrations.**
  *Why*: production has run them; rewriting history forks reality.
  *Verify*: does the diff modify any existing file in `database/migrations/`?

- [ ] **No speculative abstraction** — no interfaces with one implementation, no config for values that never vary, no generic engines for one use case.
  *Why*: the anti-overengineering rules are project law; abstractions are extracted from the *second* concrete case, not predicted.
  *Mistake*: `PaymentGatewayInterface` when only cash exists; a rules engine for the five price tiers.
  *Verify*: for each new abstraction, name the two existing call sites that need it.

- [ ] **The change stays inside the current roadmap phase** ([roadmap.md](../10-roadmap/roadmap.md)) or the PR says why not.
  *Why*: scope creep compounds; the roadmap is the agreed sequence.
  *Verify*: which phase does this belong to?

- [ ] **Known-limitation boundaries respected**: concurrency posture (stock race, invoice race) not worsened; the two-schema ledger split not casually "harmonized"; grandfathered violations not copied as precedent.
  *Verify*: does the diff copy a pattern the docs explicitly mark grandfathered/quirk?

---

## Fast path for trivial PRs

Docs-only, comment-only, or test-only diffs: sections 1, 2, 14 (secrets item), 18, and 19 still apply; everything else is N/A. There is no fast path for anything that touches `app/Services/`, migrations, or `ledger_entries` — those always run the full list.

---

**Related documents**: [AI Collaboration Guide](../09-ai-collaboration/ai-collaboration-guide.md) (the contract this checklist enforces), [Coding Standards](coding-standards.md), [Testing Strategy](testing-strategy.md), [API Conventions](api-conventions.md), [ADR index](../01-architecture/decisions/README.md).
**Future improvements**: automate the greppable items (CI script: `->all()`, root-namespace exceptions, unpaginated `->get()`, `LedgerEntry` writes outside `LedgerService`); split a dedicated frontend copy into `multidukkan-frontend` once its docs land; add a PR template that embeds the section headers.
**Open questions**: should 🔴 items be CI-blocking rather than review-blocking? Revisit when CI exists.
**Last review checklist**: [ ] every item still maps to a live rule in the docs, [ ] incident-born items (🔴) reflect the current incident list, [ ] fast path still safe. Last reviewed: 2026-07-08.
