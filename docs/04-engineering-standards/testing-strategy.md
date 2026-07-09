# Testing Strategy

PHPUnit feature tests in `tests/Feature/`, hitting the HTTP layer. Postman for manual/exploratory API testing. Unit tests are used sparingly — the value here is in feature tests that exercise controller → service → DB together, because the bugs that matter (tenant leaks, ledger math, transaction integrity) live in that composition.

## Mechanical rules (existing, non-negotiable)

1. `use RefreshDatabase;` in every test class.
2. Typed properties declared and initialized in `setUp()` (tenant, user, token, common factories).
3. **Never hardcode IDs** — factories only. Hardcoded IDs pass locally and break under parallel/reordered runs.
4. Routes always with `/api/` prefix.
5. Single-resource assertions wrap in `data`: `$response->assertJson(['data' => [...]])`.
6. **Negative tests must assert DB state**, not just the response code. A 422 with a half-written order is a worse bug than a 500; `assertDatabaseMissing` / `assertDatabaseCount` prove the transaction rolled back.

## What must have tests (priority order)

1. **Ledger math** — every `LedgerService` method: balance after each entry-type combination, credit consumption, refund caps (`cannot refund more than available`), adjust-blocked-after-refund.
2. **Tenant isolation** — for every endpoint: user of tenant A requests tenant B's resource → 403/404 **and** no rows changed. This is the cheapest security net we have.
3. **Order lifecycle atomicity** — order creation with an out-of-stock item must leave zero orders, zero order_items, zero ledger entries, zero inventory transactions. Same for cancel with unrefunded payments (blocked, nothing changes).
4. **Role boundaries** (as Phase 3 lands) — `store_staff` cannot approve transfers, cannot manage products.
5. **Unit conversion** — secondary-unit lines: stock deducted in base units, price converted, order total correct.

## Patterns

```php
// Balance assertions go through the service — the same source of truth production uses.
$this->assertEquals(150.00, app(LedgerService::class)->getBalance($this->tenant->id, $customer->id));
```

Anti-pattern: reasserting balance by summing payments in the test — the test then re-implements the bug ADR-003 exists to prevent.

```php
// Tenant isolation template
$foreign = Order::factory()->create();            // other tenant via factory defaults
$this->withToken($this->token)
     ->getJson("/api/orders/{$foreign->id}")
     ->assertNotFound();                           // or assertForbidden — match endpoint convention
```

## What we deliberately don't test

- Eloquent/Laravel internals (relations return relations).
- Resource JSON shape exhaustively — assert the fields business logic computes (status, balance), not boilerplate pass-throughs.

---
**Related documents**: [Coding Standards](coding-standards.md), [Financial Calculations](../07-business-rules/financial-calculations.md).
**Future improvements**: CI pipeline running the suite on every push; mutation testing on `LedgerService` once coverage is solid.
**Open questions**: coverage state of supplier/PO flows — audit and backfill.
**Last review checklist**: [ ] rules match `tests/Feature` reality, [ ] priority list reflects newest money paths. Last reviewed: 2026-07-08.
