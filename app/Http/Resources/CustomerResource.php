<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'tenant_id' => $this->tenant_id,
            'phone' => $this->phone,
            'price_tier' => $this->price_tier,
            'address' => $this->address,
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }
}
