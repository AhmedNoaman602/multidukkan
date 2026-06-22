<?php

namespace App\Observers;

use App\Models\Supplier;
use Illuminate\Validation\ValidationException;

class SupplierObserver
{
    /**
     * Handle the Supplier "created" event.
     */
    public function created(Supplier $supplier): void
    {
        //
    }

    /**
     * Handle the Supplier "updated" event.
     */
    public function updated(Supplier $supplier): void
    {
        //
    }

public function deleting(Supplier $supplier): void
{
    if ($supplier->products()->exists()) {
        throw ValidationException::withMessages([
            'supplier' => 'Cannot delete supplier with existing products.',
        ]);
    }

    if ($supplier->purchaseOrders()->exists()) { 
        throw ValidationException::withMessages([
            'supplier' => 'Cannot delete supplier with existing purchase orders.',
        ]);
    }

    if ($supplier->supplierPayments()->exists()) { 
        throw ValidationException::withMessages([
            'supplier' => 'Cannot delete supplier with existing payments.',
        ]);
    }
}

    /**
     * Handle the Supplier "deleted" event.
     */
    public function deleted(Supplier $supplier): void
    {
        //
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
