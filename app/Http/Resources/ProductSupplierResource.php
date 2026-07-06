<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'code'                => $this->code,
            'name'                => $this->name,
            'phone'               => $this->phone,
            'cost_price'          => $this->pivot->cost_price,
            'last_purchase_price' => $this->pivot->last_purchase_price,
            'last_purchased_at'   => $this->pivot->last_purchased_at,
            'is_preferred'        => $this->pivot->is_preferred,
            'notes'               => $this->pivot->notes,
        ];
    }
}