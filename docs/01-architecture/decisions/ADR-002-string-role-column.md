# ADR-002: String role column, not a roles table

**Status**: Accepted **Date**: recorded 2026-07-08 (decision predates record)

## Context
Three roles exist and are stable: `tenant_admin`, `store_manager`, `store_staff`. Permissions differ by role and by `users.store_id` (null = tenant-wide access).

## Decision
`users.role` is a plain string column. Role semantics:
- `tenant_admin`: `store_id = null`, full access across the tenant.
- `store_manager`: bound to one store, full access within it.
- `store_staff`: bound to one store; orders + payments + read-only inventory/products + may *request* stock transfers, never approve.

## Alternatives rejected
- **spatie/laravel-permission or a roles/permissions table**: designed for dynamic, admin-editable permission matrices. Ours are three fixed roles whose meaning is business logic, not data. A table adds joins, seeds, and cache invalidation for zero flexibility we'd use.
- **Enum class only**: PHP enum backing the string is fine and encouraged, but the storage stays a string column.

## Consequences
- Adding a role = migration-free (string), but permission logic is code — centralize checks in Policies/middleware as Phase 3 lands, not scattered `if ($user->role === ...)` in controllers.
- If a future customer needs custom per-user permissions, that is the trigger to revisit — write a superseding ADR then, not before.

---
**Related**: [Backend Architecture](../backend-architecture.md), [Roadmap](../../10-roadmap/roadmap.md) (Phase 3). **Open questions**: Policies vs middleware for role checks — decide during Phase 3. **Last reviewed**: 2026-07-08.
