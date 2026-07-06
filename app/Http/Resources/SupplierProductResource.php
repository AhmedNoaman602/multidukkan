<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'sku'                 => $this->sku,
            'unit'                => $this->unit,
            'price'               => $this->price,
            'price_a'             => $this->price_a,
            'price_b'             => $this->price_b,
            'price_c'             => $this->price_c,
            'price_d'             => $this->price_d,
            'price_e'             => $this->price_e,
            'secondary_unit'      => $this->secondary_unit,
            'conversion_factor'   => $this->conversion_factor,
            'total_stock'         => $this->inventories->sum('quantity'),
            'cost_price'          => $this->pivot->cost_price,
            'last_purchase_price' => $this->pivot->last_purchase_price,
            'last_purchased_at'   => $this->pivot->last_purchased_at,
            'is_preferred'        => $this->pivot->is_preferred,
            'notes'               => $this->pivot->notes,
        ];
    }
}