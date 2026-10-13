<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
{
    $showsCost = $request->user()?->can('view-cost-data') ?? false;

    return [
        'id'                => $this->id,
        'tenant_id'         => $this->tenant_id,
        'name'              => $this->name,
        'sku'               => $this->sku,
        'cost_price'        => $this->when($showsCost, fn () => $this->cost_price),
        'price'             => $this->price,
        'price_a'           => $this->price_a,
        'price_b'           => $this->price_b,
        'price_c'           => $this->price_c,
        'price_d'           => $this->price_d,
        'price_e'           => $this->price_e,
        'profit_margin'     => $this->when($showsCost, fn () => $this->marginPercent($this->price)),
        'profit_margin_a'   => $this->when($showsCost, fn () => $this->marginPercent($this->price_a ?? $this->price)),
        'profit_margin_b'   => $this->when($showsCost, fn () => $this->marginPercent($this->price_b ?? $this->price)),
        'profit_margin_c'   => $this->when($showsCost, fn () => $this->marginPercent($this->price_c ?? $this->price)),
        'profit_margin_d'   => $this->when($showsCost, fn () => $this->marginPercent($this->price_d ?? $this->price)),
        'profit_margin_e'   => $this->when($showsCost, fn () => $this->marginPercent($this->price_e ?? $this->price)),

        'unit'              => $this->unit,
        'secondary_unit'    => $this->secondary_unit,
        'conversion_factor' => $this->conversion_factor,
        'created_at'        => $this->created_at?->toIso8601ZuluString('microsecond'),
        'stocks'            => $this->inventories->map(fn($inv) => [
            'warehouse_id'   => $inv->warehouse_id,
            'warehouse_name' => $inv->warehouse->name,
            'warehouse_type' => $inv->warehouse->type,
            'store_id'       => $inv->warehouse->store_id,
            'quantity'       => $inv->quantity,
            'threshold'      => $inv->threshold,
        ]),
    ];
}

    // Margin % = (price − cost) / price × 100, same rounding and zero-price rule as the report.
    private function marginPercent($price): ?float
    {
        if ($this->cost_price === null) {
            return null;
        }

        $price = (float) $price;

        return $price > 0 ? round(($price - $this->cost_price) / $price * 100, 1) : 0.0;
    }
}
