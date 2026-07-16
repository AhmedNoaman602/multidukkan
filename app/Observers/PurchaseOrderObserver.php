<?php

namespace App\Observers;

use App\Models\PurchaseOrder;
use App\Models\AuditLog;
use Illuminate\Validation\ValidationException;

class PurchaseOrderObserver
{
    /**
     * Handle the PurchaseOrder "created" event.
     *
     * Deliberately does not log 'created'/'deleted' here — ledger_entries'
     * PURCHASE_CHARGE/PURCHASE_REVERSAL already own those moments; logging
     * both would show the same event twice in the merged activity feed.
     */
    public function created(PurchaseOrder $purchaseOrder): void
    {
        //
    }

    /**
     * Handle the PurchaseOrder "updated" event.
     */
    public function updated(PurchaseOrder $purchaseOrder): void
    {
        $changes = collect($purchaseOrder->getChanges())
            ->except('updated_at')
            ->mapWithKeys(fn ($newValue, $field) => [
                $field => [$purchaseOrder->getOriginal($field), $newValue]
            ])
            ->toArray();

        AuditLog::create([
            'tenant_id' => $purchaseOrder->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => PurchaseOrder::class,
            'auditable_id' => $purchaseOrder->id,
            'action' => 'updated',
            'changes' => $changes,
        ]);
    }

    /**
     * Handle the PurchaseOrder "deleting" event.
     *
     * Mirrors OrderObserver::deleting() — blocks cancellation while payments exist, so
     * reversing the full PURCHASE_CHARGE never leaves a SUPPLIER_PAYMENT credit floating
     * with no PO to trace it to. Reverse the payments first (DELETE /supplier-payments/{id}).
     */
    public function deleting(PurchaseOrder $purchaseOrder): void
    {
        if ($purchaseOrder->supplierPayments()->exists()) {
            throw ValidationException::withMessages([
                'purchase_order' => 'Cannot cancel a purchase order with payments. Remove payments first.',
            ]);
        }
    }
}
