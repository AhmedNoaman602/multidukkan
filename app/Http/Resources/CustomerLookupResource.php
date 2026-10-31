<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// A customer as a picker shows it; price_tier lets New Order price the lines. No balance.
class CustomerLookupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'code'       => $this->code,
            'name'       => $this->name,
            'phone'      => $this->phone,
            'price_tier' => $this->price_tier,
        ];
    }
}
