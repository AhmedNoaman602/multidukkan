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
    return [
        'id'                => $this->id,
        'tenant_id'         => $this->tenant_id,
        'name'              => $this->name,
        'sku'               => $this->sku,
        'cost_price'        => $this->cost_price,
        'opening_quantity'  => $this->opening_quantity,
        'price'             => $this->price,
        'price_a'           => $this->price_a,
        'price_b'           => $this->price_b,
        'price_c'           => $this->price_c,
        'price_d'           => $this->price_d,
        'price_e'           => $this->price_e,
        'profit_margin'   => $this->cost_price !== null 
    ? round($this->price - $this->cost_price, 2) 
    : null,

    'profit_margin_a' => $this->cost_price !== null 
    ? round(($this->price_a ?? $this->price) - $this->cost_price, 2) 
    : null,

    'profit_margin_b' => $this->cost_price !== null 
    ? round(($this->price_b ?? $this->price) - $this->cost_price, 2) 
    : null,
    'profit_margin_c' => $this->cost_price !== null 
    ? round(($this->price_c ?? $this->price) - $this->cost_price, 2) 
    : null,
    'profit_margin_d' => $this->cost_price !== null 
    ? round(($this->price_d ?? $this->price) - $this->cost_price, 2) 
    : null,
    'profit_margin_e' => $this->cost_price !== null 
    ? round(($this->price_e ?? $this->price) - $this->cost_price, 2) 
    : null,

        'unit'              => $this->unit,
        'secondary_unit'    => $this->secondary_unit,
        'conversion_factor' => $this->conversion_factor,
        'created_at'        => $this->created_at->format('Y-m-d H:i:s'),
        'stocks'            => $this->inventories->map(fn($inv) => [
            'warehouse_id'   => $inv->warehouse_id,
            'warehouse_name' => $inv->warehouse->name,
            'quantity'       => $inv->quantity,
            'threshold'      => $inv->threshold,
        ]),
    ];
}
}
