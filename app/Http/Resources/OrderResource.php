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
    $total     = $this->items->sum(fn($i) => $i->unit_price * $i->quantity);
    $totalPaid = $this->payments->sum('amount');

    return [
        'id'         => $this->id,
        'tenant_id'  => $this->tenant_id,
        'store_id'   => $this->store_id,
        'customer_id'=> $this->customer_id,
        'customer_name'=> $this->customer->name,
        'created_by' => $this->created_by,
        'notes'      => $this->notes,
        'total'      => $total,
        'status'     => $this->resolveStatus($totalPaid, $total),
        'items_count' => $this->items->count(),
        'items'      => $this->items->map(fn($item) => [
            'product_name' => $item->product_name,
            'quantity'     => $item->quantity,
            'unit_price'   => $item->unit_price,
            'total'        => $item->unit_price * $item->quantity,
        ]),
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
