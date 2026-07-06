<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class SupplierResource extends JsonResource
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
            'code'              => $this->code,
            'name'              => $this->name,
            'phone'             => $this->phone,
            'email'             => $this->email,
            'address'           => $this->address,
            'area'              => $this->area,
            'notes'             => $this->notes,
            'tenant_id'         => $this->tenant_id,
            'balance'           => $this->balance ?? 0, 
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
