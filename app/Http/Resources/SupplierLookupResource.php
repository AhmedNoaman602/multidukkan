<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// A supplier as a picker shows it. No balance.
class SupplierLookupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'code'  => $this->code,
            'name'  => $this->name,
            'phone' => $this->phone,
        ];
    }
}
