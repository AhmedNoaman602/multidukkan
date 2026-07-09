# UX Patterns — Stub (lives in `multidukkan-frontend`)

To be written in the frontend repo, one file per pattern, **each written the first time the pattern is built for real** (not speculatively). Required set, derived from the API surface that already exists:

- `screen-templates.md` — list screen (paginated table + search + filters), detail screen (header + tabs: info / ledger / orders), POS entry screen.
- `forms.md` — server-driven validation display (Laravel 422 `errors` shape maps 1:1 to field errors — including business-rule 422s like insufficient stock), optimistic vs pessimistic submit rules (money forms are always pessimistic).
- `tables.md` — every list is paginated (backend guarantees it; UI must not fake infinite scroll by over-fetching), balance columns come from batch endpoints.
- `empty-error-loading-states.md` — Arabic-first copy, skeletons for the speed-perception requirement.
- `search.md` — the global `/search` endpoint pattern; per-list filtering conventions.
- `mobile.md` — deferred until the mobile roadmap item activates.

---
**Related**: [02-design-system](../02-design-system/README.md), [api-conventions.md](../04-engineering-standards/api-conventions.md). **Last reviewed**: 2026-07-08.
