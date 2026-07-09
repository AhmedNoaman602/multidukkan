# Frontend Development Guide — Stub (lives in `multidukkan-frontend`)

The React/Vite/React-Query guide belongs beside the code it governs. Required contents when written there:

- **API layer**: single axios/fetch client attaching the Sanctum bearer token; all endpoints typed against [api-conventions.md](../04-engineering-standards/api-conventions.md); `data`-unwrapping in one place.
- **React Query**: query-key convention (`['orders', tenantScope, filters]`), invalidation map per mutation (creating a payment invalidates the order, the customer balance, the dashboard — derive the map from the backend's ledger side-effects, they are documented in [order-lifecycle.md](../07-business-rules/order-lifecycle.md)).
- **Money rule, frontend edition**: the client **never computes** balances/totals/status — it displays what the API returns. Client-side money math is forbidden for the same reason as [ADR-003](../01-architecture/decisions/ADR-003-ledger-single-source-of-truth.md).
- **State management**: React Query for server state; local UI state in components; introduce a store (zustand) only for the POS cart, if at all.
- **Routing / error handling / file organization**: document the *existing* frontend architecture (the founder states one exists) rather than inventing a new one — an AI session should inventory `multidukkan-frontend` first and write these docs from reality, exactly as this repo's docs were written.

---
**Related**: [api-conventions.md](../04-engineering-standards/api-conventions.md), [02-design-system](../02-design-system/README.md). **Last reviewed**: 2026-07-08.
