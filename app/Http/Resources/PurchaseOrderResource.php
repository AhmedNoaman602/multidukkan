<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class PurchaseOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
       public function toArray(Request $request): array
{
    $subtotal  = $this->items->sum(fn($i) => $i->unit_price * $i->quantity);
    // purchase_orders.total is the authoritative charge amount, mirroring ADR-004 for orders.
    $total     = (float) $this->total;
    $totalPaid = $this->supplierPayments->sum('amount');

    return [
        'id'             => $this->id,
        'invoice_number' => $this->invoice_number,
        'tenant_id'      => $this->tenant_id,
        'supplier_id'    => $this->supplier_id,
        'supplier_name'  => $this->supplier_name_snapshot ?? $this->supplier?->name ?? __('messages.deleted_supplier'),
        'supplier_phone' => $this->supplier->phone ?? '',
        'notes'          => $this->notes,
        'subtotal'       => round($subtotal, 2), 
        'total'          => $total,
        'status'         => $this->resolveStatus($totalPaid, $total),
        'items_count'    => $this->items->count(),
        'items'          => $this->items->map(fn($item) => [
            'product_name' => $item->product?->name ?? __('messages.deleted_product'),
            'quantity'     => $item->quantity,
            'unit_price'   => $item->unit_price,
            'total'        => $item->unit_price * $item->quantity,
        ]),
        'amount_remaining' => max(0, round($total - $totalPaid , 2)),
        'created_at' => $this->created_at->toDateTimeString(),
    ];
}
private function resolveStatus(float $totalPaid, float $total): string
{
    if ($totalPaid >= $total) {
        return 'paid';
    }

    return 'unpaid';
}
}
