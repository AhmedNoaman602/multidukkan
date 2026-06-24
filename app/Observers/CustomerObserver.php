<?php

namespace App\Observers;

use App\Models\Customer;
use App\Services\LedgerService;
use Illuminate\Validation\ValidationException;

class CustomerObserver
{
    /**
     * Handle the Customer "created" event.
     */
    public function created(Customer $customer): void
    {
        //
    }

    /**
     * Handle the Customer "updated" event.
     */
    public function updated(Customer $customer): void
    {
        //
    }

public function deleting(Customer $customer): void
{
    if ($customer->orders()->withTrashed()->exists()) {
        throw ValidationException::withMessages([
            'customer' => 'Cannot delete a customer who has existing orders.',
        ]);
    }

    if ($customer->payments()->withTrashed()->exists()) {
        throw ValidationException::withMessages([
            'customer' => 'Cannot delete a customer who has existing payments.',
        ]);
    }

    $balance = app(LedgerService::class)
        ->getBalance($customer->tenant_id, $customer->id);

    if ($balance != 0) {
        throw ValidationException::withMessages([
            'customer' => 'Cannot delete a customer with outstanding balance.',
        ]);
    }
}
    /**
     * Handle the Customer "deleted" event.
     */
    public function deleted(Customer $customer): void
    {
        //
    }

    /**
     * Handle the Customer "restored" event.
     */
    public function restored(Customer $customer): void
    {
        //
    }

    /**
     * Handle the Customer "force deleted" event.
     */
    public function forceDeleted(Customer $customer): void
    {
        //
    }
}
