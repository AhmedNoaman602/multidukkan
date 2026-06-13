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
        'warehouse_name' => $this->warehouse?->name ?? '—',
        'product_id'     => $this->product_id,
        'product_name'   => $this->product?->name ?? '—',
        'product_unit'   => $this->product->unit ?? null,
        'quantity'       => $this->quantity,
        'threshold'      => $this->threshold,
        'low_stock'      => $this->quantity <= $this->threshold,
        'secondary_unit' => $this->product->secondary_unit ?? null,
        'conversion_factor' => $this->product->conversion_factor ?? null,
        'store_id'       => $this->warehouse?->store_id ?? null,
        'store_name'     => $this->warehouse?->store?->name ?? '—',
        'created_at'     => $this->created_at,
    ];
}
}