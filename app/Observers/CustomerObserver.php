<?php

namespace App\Observers;

use App\Models\Customer;
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
        if ($customer->orders()->exists()) {
            throw ValidationException::withMessages([
                'customer' => 'Cannot delete customer with existing orders.',
            ]);
        }

        if ($customer->payments()->exists()) {
            throw ValidationException::withMessages([
                'customer' => 'Cannot delete customer with existing payments.',
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
