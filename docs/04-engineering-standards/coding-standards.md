# Coding Standards (Laravel Backend)

These are the conventions the codebase already follows plus the gaps that keep causing review comments. Match existing style; don't import styles from other projects.

## The non-negotiables (violations are bugs, not style issues)

1. **`$request->validated()` only — never `$request->all()`.** `all()` lets unvalidated fields flow into `Model::create` on `$fillable` models. This is how mass-assignment bugs happen.
2. **Every query on a business table scopes by `tenant_id`.** There is no global scope doing it for you. When accepting foreign IDs in input, validate with `App\Rules\BelongsToTenant`; when loading them in services, re-verify (pattern: the tenant-guard block in `PurchaseOrderService::createPurchaseOrder`).
3. **No financial math outside `LedgerService`** ([ADR-003](../01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md)).
4. **`DB::transaction` around any multi-table money/stock operation** (see [backend-architecture.md](../01-architecture/backend-architecture.md#transactional-integrity-rule)).
5. **API Resources for all responses.** Never `return $model` / `return $collection` raw — raw models leak columns added by future migrations (this is how `tenant_id` and internal flags end up in public payloads).
6. **Type-hint all service method parameters and return types.** Existing services comply; keep it that way.

## Naming

| Thing | Convention | Example |
|---|---|---|
| Controllers | Singular resource + `Controller`, in `Api\V1` | `PurchaseOrderController` |
| Services | Domain noun + `Service` | `SupplierPaymentService` |
| FormRequests | `Store`/`Update` + resource + `Request` | `StorePurchaseOrderRequest` |
| Resources | Resource + `Resource` | `SupplierProductResource` |
| Ledger types | SCREAMING_SNAKE, past-tense-noun style, registered in `LedgerEntry::TYPES` **and** the DB enum migration | `CREDIT_CONSUMED` |
| Money variables | Say what stage of the math they are | `$chargeAmount`, `$appliedAmount`, `$leftover` — not `$amt`, `$val` |

## Patterns to copy (and their anti-patterns)

**Batch, don't loop-query.** Preferred: `getBalancesForCustomers()`, the `$products->keyBy('id')` prefetch in `OrderService::createOrder`, the single `syncWithoutDetaching` in `PurchaseOrderService`. Anti-pattern: any `Model::find()` or service call inside a `foreach` over user input or a result set. Performance is a top-priority requirement; N+1s get fixed on sight.

**Fail loudly on tenant mismatch.** Preferred: throw when a scoped lookup comes back short (`$products->count() !== $productIds->count()`). Anti-pattern: silently filtering out foreign rows — it hides both attacks and frontend bugs.

**User-facing validation errors via `ValidationException::withMessages([...])`** (namespaced `Illuminate\Validation\ValidationException`). Arabic messages are acceptable where the audience is the merchant (`InventoryService::checkStock`). Anti-pattern: `throw new \ValidationException(...)` — the root-namespace class does not exist and fatals at runtime (a live instance of this exists in `PaymentService::processDirectPayment`, flagged for fix).

**Services receive `User $user` as a parameter** (pattern: `PaymentService`). Anti-pattern for new code: `auth()->user()` inside services (legacy instances exist in `OrderService`/`PurchaseOrderService`).

**Rounding**: money is `round(x, 2)` at every computation boundary and `decimal:2` cast on models. Never compare floats with `===`; compare rounded values or use `>=` thresholds like `Order::isSettled()`.

## Comments

Comment the *constraint*, not the narration. Good: the tenant-guard rationale in `PurchaseOrderService`. Bad: `// create the order` above `Order::create`. Delete stale bug-comments when the fix lands (one is pending in `PurchaseOrderService`, see [ADR-008](../01-architecture/decisions/ADR-008-weighted-average-costing.md)).

---
**Related documents**: [Backend Architecture](../01-architecture/backend-architecture.md), [Testing Strategy](testing-strategy.md), [AI Collaboration Guide](../09-ai-collaboration/ai-collaboration-guide.md).
**Future improvements**: adopt Laravel Pint config committed to the repo; add PHPStan (level 5+) to CI.
**Open questions**: none pressing.
**Last review checklist**: [ ] non-negotiables match AI guide, [ ] anti-pattern examples still exist or note fixed. Last reviewed: 2026-07-08.
