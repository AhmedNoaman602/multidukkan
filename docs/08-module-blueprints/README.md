# Module Blueprints — Template (written just-in-time, never speculatively)

A blueprint is written **when a module is about to be built**, as the first deliverable of that work — not before. Speculative blueprints for modules years away (Accounting, Employees, White-label) are banned by the [roadmap](../10-roadmap/roadmap.md); they would rot before use.

**Next blueprint due**: `stock-transfers.md` (Phase 3 — rules already locked, see [inventory-and-warehouses.md](../06-domain/inventory-and-warehouses.md#phase-3-preview--stock-transfers-not-built)).

## Template

```markdown
# Module: <name>
## Responsibility — one paragraph; what it owns, what it explicitly does not
## Domain — entities, migrations, relationships (link/extend docs/06-domain/*)
## Business rules — lifecycles, calculations, edge cases (link/extend docs/07-business-rules/*)
## API surface — routes, FormRequests, Resources (follow docs/04-engineering-standards/api-conventions.md)
## Service design — methods with full signatures, transaction boundaries, which existing services it calls
## Ledger/inventory impact — which entry types / transaction types it writes (or the word: none)
## Role access — tenant_admin / store_manager / store_staff matrix
## Tests — the negative cases that must exist before merge
## Frontend notes — screens, query keys, invalidations (for the frontend repo)
## Future expansion — one-liners only
```

---
**Related**: [Roadmap](../10-roadmap/roadmap.md), [Backend Architecture](../01-architecture/backend-architecture.md). **Last reviewed**: 2026-07-08.
