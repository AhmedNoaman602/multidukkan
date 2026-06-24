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
    if ($supplier->purchaseOrders()->withTrashed()->exists()) {
        throw ValidationException::withMessages([
            'supplier' => 'Cannot delete a supplier with existing purchase orders.',
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
