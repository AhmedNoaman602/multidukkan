# Architecture Decision Records

ADRs capture decisions that are **expensive to reverse** or **tempting to re-litigate**. They are immutable: to change a decision, write a new ADR with status `Supersedes ADR-XXX` and mark the old one `Superseded`.

If you are an AI session and a change you're planning contradicts an `Accepted` ADR: **stop and tell the user which ADR blocks you**. Do not implement around it.

## Index

| ADR | Title | Status |
|---|---|---|
| [ADR-001](ADR-001-sanctum-token-auth.md) | Sanctum token auth | Accepted |
| [ADR-002](ADR-002-string-role-column.md) | String role column, not a roles table | Accepted |
| [ADR-003](ADR-003-ledger-single-source-of-truth.md) | LedgerService is the only source of financial truth | Accepted |
| [ADR-004](ADR-004-stored-order-total.md) | `order.total` is stored, synced via `adjustOrderCharge` | Accepted — supersedes the original "never store total" rule |
| [ADR-005](ADR-005-snapshot-pricing.md) | Snapshot name + price on line items at sale time | Accepted |
| [ADR-006](ADR-006-ledger-mutability-boundaries.md) | Ledger append-only, with two narrow edit exceptions | Accepted — amends the original "append-only, no exceptions" rule |
| [ADR-007](ADR-007-nullable-warehouse-on-line-items.md) | `warehouse_id` nullable on line items | Accepted |
| [ADR-008](ADR-008-weighted-average-costing.md) | Weighted-average cost on `products.cost_price` | Accepted |

## Template

```markdown
# ADR-NNN: Title
**Status**: Proposed | Accepted | Superseded by ADR-MMM
**Date**: YYYY-MM-DD
## Context   — the forces at play, what problem forced a decision
## Decision  — what we chose, stated imperatively
## Alternatives rejected — and why
## Consequences — good, bad, and what future work this creates
```
