<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    /**
     * Disable JSON wrapping, matching OrderResource / PurchaseOrderResource.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'category'     => $this->category,          // raw code only — frontend owns presentation
            'amount'       => (float) $this->amount,    // decimal:2 returns a string; float at the boundary
            'description'  => $this->description,
            'expense_date' => $this->expense_date->toDateString(),
            'store'        => $this->whenLoaded('store', fn () => [
                'id'   => $this->store->id,
                'name' => $this->store->name,
            ]),
            'creator'      => [
                'id'   => $this->created_by,
                'name' => $this->created_by_name ?? $this->creator?->name,
            ],
            'created_at'   => $this->created_at?->toIso8601ZuluString('microsecond'),
        ];
    }
}
