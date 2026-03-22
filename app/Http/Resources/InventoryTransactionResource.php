<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'tenant_id'      => $this->tenant_id,
            'warehouse_id'   => $this->warehouse_id,
            'product_id'     => $this->product_id,
            'type'           => $this->type,
            'quantity'       => $this->quantity,
            'reference_type' => $this->reference_type,
            'reference_id'   => $this->reference_id,
            'notes'          => $this->notes,
            'created_at'     => $this->created_at,
        ];
    }
}