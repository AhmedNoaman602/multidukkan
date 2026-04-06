<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
   public function toArray(Request $request): array
{
    return [
        'id'             => $this->id,
        'tenant_id'      => $this->tenant_id,
        'warehouse_id'   => $this->warehouse_id,
        'product_id'     => $this->product_id,
        'quantity'       => $this->quantity,
        'threshold'      => $this->threshold,
        'low_stock'      => $this->quantity <= $this->threshold,
        'product_name'   => $this->product->name,
        'warehouse_name' => $this->warehouse->name,
        'created_at'     => $this->created_at,
    ];
}
}