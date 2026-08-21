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
    // orders.total is the authoritative charge amount (ADR-004) — may differ from
    // subtotal - discount when a manual_total override was applied at creation.
    $total     = (float) $this->total;
    $totalPaid = $this->settledAmount();
    $displayPaid = min($totalPaid, $total);

    return [
        'id'         => $this->id,
        'invoice_number' => $this->invoice_number,
        'tenant_id'  => $this->tenant_id,
        'store_id'   => $this->store_id,
        'customer_id'=> $this->customer_id,
        'customer_name' => $this->customer_name_snapshot ?? $this->customer?->name ?? __('messages.deleted_customer'),
        'created_by' => $this->created_by,
        'notes'      => $this->notes,
        'subtotal'       => round($subtotal, 2),  
        'discount'       => $discount,
        'total'      => $total,
        'paid' => $displayPaid,
        'customer_phone' => $this->customer?->phone ?? '',
        'payments_count' => $this->payments->count(),
        'payments' => $this->payments
                ->filter(fn($p) => !$p->is_auto_reversible)
                ->map(fn($p) => [
                    'id'              => $p->id,
                    'order_id'        => $p->order_id,
                    'amount'          => $p->amount,
                    'refunded_amount' => $p->refunded_amount ?? 0,
                    'method'          => $p->method,
                ])
                ->values(),
        'refundable_amount' => $this->payments
                        ->filter(fn($p) => !$p->is_auto_reversible)
                        ->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0)), 

        'store_name'     => $this->store?->name ?? '',
        'status'     => $this->resolveStatus($totalPaid, $total),
        'items_count' => $this->items->sum(fn($item) =>
            $item->unit_type === 'secondary' && $item->product?->conversion_factor
                ? $item->quantity * $item->product->conversion_factor
                : $item->quantity
        ),
        'items'      => $this->items->map(fn($item) => [
            'id' => $item->id,
            'product_name' => $item->product_name,
            'warehouse_name' => $item->warehouse?->name ?? '—',
            'quantity'     => $item->quantity,
            'unit_price'   => $item->unit_price,
            'unit_type'    => $item->unit_type,
            'unit_label'   => $item->unit_type === 'secondary' 
                        ? ($item->product?->secondary_unit ?? $item->unit_type)
                        : ($item->product?->unit ?? 'base'),
            'total'        => $item->unit_price * $item->quantity,
            'cost_price'     => $item->product?->cost_price ?? null,
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
