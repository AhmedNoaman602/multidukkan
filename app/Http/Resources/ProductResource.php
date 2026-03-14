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
           'unit'       => $this->unit,
           'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
