<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
/**
 * API Resource for formatting Order data with payment status resolution.
 * 
 * This resource transforms the Order model into a JSON-friendly array,
 * including calculated totals and a resolved status (paid/unpaid) based
 * on the customer's current ledger balance.
 * 
 * Connections:
 * - Uses {@see \App\Services\LedgerService} to check customer balances.
 */
class OrderResource extends JsonResource
{
    /**
     * Disable JSON wrapping for this resource.
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
    $subtotal  = $this->items->sum(fn($i) => $i->unit_price * $i->quantity);
    $discount  = (float) ($this->discount ?? 0);
    $total     = max(0, round($subtotal - $discount, 2));
    $totalPaid = $this->payments->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0));
    $displayPaid = min($totalPaid, $total);

    return [
        'id'         => $this->id,
        'invoice_number' => $this->invoice_number,
        'tenant_id'  => $this->tenant_id,
        'store_id'   => $this->store_id,
        'customer_id'=> $this->customer_id,
        'customer_name' => $this->customer?->name ?? 'Deleted Customer',
        'created_by' => $this->created_by,
        'notes'      => $this->notes,
        'subtotal'       => round($subtotal, 2),  
        'discount'       => $discount,
        'total'      => $total,
        'paid' => $displayPaid,
        'customer_phone' => $this->customer?->phone ?? '',
        'store_name'     => $this->store?->name ?? '',
        'status'     => $this->resolveStatus($totalPaid, $total),
        'items_count' => $this->items->count(),
        'items'      => $this->items->map(fn($item) => [
            'id' => $item->id,
            'product_name' => $item->product_name,
            'quantity'     => $item->quantity,
            'unit_price'   => $item->unit_price,
            'unit_type'    => $item->unit_type,
            'unit_label'   => $item->unit_type === 'secondary' 
                        ? ($item->product?->secondary_unit ?? $item->unit_type)
                        : ($item->product?->unit ?? 'base'),
            'total'        => $item->unit_price * $item->quantity,
        ]),
        'amount_remaining' => max(0, round($total - $totalPaid , 2)),
        'created_at' => $this->created_at->toDateTimeString(),
        'order_date' => $this->order_date,
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
