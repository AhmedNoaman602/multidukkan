<?php

namespace App\Observers;

use App\Models\Supplier;
use App\Models\AuditLog;
use Illuminate\Validation\ValidationException;

class SupplierObserver
{
    /**
     * Handle the Supplier "created" event.
     */
    public function created(Supplier $supplier): void
    {
        AuditLog::create([
            'tenant_id' => $supplier->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Supplier::class,
            'auditable_id' => $supplier->id,
            'action' => 'created',
            'changes' => null,
        ]);
    }

    /**
     * Handle the Supplier "updated" event.
     */
    public function updated(Supplier $supplier): void
    {
        $changes = collect($supplier->getChanges())
            ->except('updated_at')
            ->mapWithKeys(fn ($newValue, $field) => [
                $field => [$supplier->getOriginal($field), $newValue]
            ])
            ->toArray();

        AuditLog::create([
            'tenant_id' => $supplier->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Supplier::class,
            'auditable_id' => $supplier->id,
            'action' => 'updated',
            'changes' => $changes,
        ]);
    }

public function deleting(Supplier $supplier): void
{
    if ($supplier->purchaseOrders()->withTrashed()->exists()) {
        throw ValidationException::withMessages([
            'supplier' => __('messages.supplier_has_purchase_orders'),
        ]);
    }
}

    /**
     * Handle the Supplier "deleted" event.
     */
    public function deleted(Supplier $supplier): void
    {
        AuditLog::create([
            'tenant_id' => $supplier->tenant_id,
            'user_id' => auth()->id(),
            'auditable_type' => Supplier::class,
            'auditable_id' => $supplier->id,
            'action' => 'deleted',
            'changes' => null,
        ]);
    }

    /**
     * Handle the Supplier "restored" event.
     */
    public function restored(Supplier $supplier): void
    {
        //
    }

    /**
     * Handle the Supplier "force deleted" event.
     */
    public function forceDeleted(Supplier $supplier): void
    {
        //
    }
}
