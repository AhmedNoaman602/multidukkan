<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $location = fn ($warehouse) => $warehouse ? [
            'id'   => $warehouse->id,
            'name' => $warehouse->name,
            'type' => $warehouse->type,
        ] : null;

        return [
            'id'       => $this->id,
            'store_id' => $this->store_id,
            'type'     => $this->type,
            'status'   => $this->status,
            'from'     => $location($this->fromWarehouse),
            'to'       => $location($this->toWarehouse),
            'creator'  => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null,
            'order'    => $this->order ? ['id' => $this->order->id, 'invoice_number' => $this->order->invoice_number] : null,
            'notes'    => $this->notes,
            'items'    => $this->items->map(fn ($item) => [
                'id'                => $item->id,
                'product_id'        => $item->product_id,
                'product_name'      => $item->product?->name ?? __('messages.deleted_product'),
                'quantity'          => $item->quantity,
                'unit_type'         => $item->unit_type,
                'unit_name'         => $item->unit_name,
                'conversion_factor' => $item->conversion_factor,
                'base_quantity'     => $item->baseQuantity(),
                'base_unit'         => $item->product?->unit,
            ])->values(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString('microsecond'),
            'created_at'   => $this->created_at?->toIso8601ZuluString('microsecond'),
            'updated_at'   => $this->updated_at?->toIso8601ZuluString('microsecond'),
        ];
    }
}
