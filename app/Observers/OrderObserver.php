<?php

namespace App\Observers;

use App\Models\Order;
use Illuminate\Validation\ValidationException;
class OrderObserver
{
    /**
     * Handle the Order "created" event.
     */
    public function created(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        //
    }

    public function updating(Order $order): void
    {
        
    }

    public function deleting(Order $order): void
    {
        if($order->payments()->exists()) {
            throw ValidationException::withMessages([
                'order' => 'Cannot delete an order with payments. Remove payments first.',
            ]);
        }
    }

    /**
     * Handle the Order "deleted" event.
     */
    public function deleted(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "restored" event.
     */
    public function restored(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "force deleted" event.
     */
    public function forceDeleted(Order $order): void
    {
        //
    }
}
