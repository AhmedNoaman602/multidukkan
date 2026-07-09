# Products & Units

A product is a sellable thing with **five optional price tiers**, **up to two units of measure**, and a **weighted-average cost**.

## Pricing model

| Column | Meaning |
|---|---|
| `price` | Base/default price — the fallback for every tier |
| `price_a` … `price_e` | Tier prices, each nullable; a null tier falls back to `price` |
| `cost_price` | Weighted-average purchase cost, maintained by `PurchaseOrderService` ([ADR-008](../01-architecture/decisions/ADR-008-weighted-average-costing.md)) |

Tier resolution (in `OrderService`): `match ($customer->price_tier) { 'a' => $product->price_a ?? $product->price, ... default => $product->price }`. The resolved (or manually overridden) price is snapshotted onto the order item — **product price edits never affect existing orders** ([ADR-005](../01-architecture/decisions/ADR-005-snapshot-pricing.md)).

## Dual-unit system

Products may sell in a base unit (`unit`, default `pcs`) and a `secondary_unit` (e.g. carton) related by `conversion_factor` (base units per secondary unit).

**The one rule that keeps this sane: stock and cost always live in base units.** Any line entered as `unit_type = 'secondary'` is converted immediately:
- stock quantity → `quantity × conversion_factor`
- unit price → `tier_price × conversion_factor` (unless manually overridden)

This conversion appears (deliberately duplicated for now) in `OrderService`, `PurchaseOrderService`, and `InventoryService::adjustStock`. If you touch unit logic, update all three or extract a shared helper — partial updates here corrupt stock counts silently. See [costing-and-inventory-rules.md](../07-business-rules/costing-and-inventory-rules.md).

The `units` table (`UnitController`) is a tenant-defined list of unit names for UI selection — it carries no conversion logic itself.

## Other columns

`sku`, `description` + `description_ar`/`description_en` (bilingual, AI-generated via `POST /ai/describe-product`), `opening_quantity` + opening fields (migration 2026-06-19) for onboarding existing stock.

## Deletion semantics

Products are managed by `tenant_admin` only (locked decision). Product delete cascades to its `inventory` rows via the model's `booted()` hook — but `inventory_transactions` and order/PO item snapshots remain. This is the ceiling of acceptable model-level logic; anything more belongs in a service.

## Supplier relationship

`supplier_products` pivot: `cost_price`, `last_purchase_price`, `last_purchased_at`, `is_preferred`, `notes` — per-supplier purchasing intelligence, updated on every PO (`syncWithoutDetaching`). Distinct from the product's own `cost_price` (blended average across all suppliers).

---
**Related documents**: [Suppliers & POs](suppliers-and-purchase-orders.md), [Costing Rules](../07-business-rules/costing-and-inventory-rules.md), [Orders](orders.md).
**Future improvements**: extract a `UnitConverter` helper to end the triplicated conversion logic; product images.
**Open questions**: `Product::booted()` deleting inventories bypasses `InventoryService` — should it log ADJUSTMENT_OUT transactions instead of silently removing rows?
**Last review checklist**: [ ] price tier fallback matches code, [ ] conversion sites list still accurate. Last reviewed: 2026-07-08.
