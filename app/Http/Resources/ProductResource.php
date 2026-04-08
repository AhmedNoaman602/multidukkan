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
           'id'         => $this->id,
           'tenant_id'  => $this->tenant_id,
           'name'       => $this->name,
           'sku'        => $this->sku,
           'price'      => $this->price,
           'price_a'      => $this->price_a,
           'price_b'      => $this->price_b,
           'price_c'      => $this->price_c,
           'price_d'      => $this->price_d,
           'price_e'      => $this->price_e,
           'unit'       => $this->unit,
           'secondary_unit'    => $this->secondary_unit,
           'conversion_factor' => $this->conversion_factor,
           'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
