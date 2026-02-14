<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
class OrderObserver
{
    /**
     * Handle the Order "created" event.
     */
   public function created(Order $order): void
{
    DB::transaction(function () use ($order) {

        $invoice = Invoice::create([
            'order_id'       => $order->id,
            'customer_id'    => $order->customer_id,
            'customer_name'  => $order->customer_name ?? $order->customer?->name,
            'total'          => $order->total,
            'payment_status' => $order->payment_status,
        ]);

        LedgerEntry::create([
            'account_type'   => 'customer',
            'account_id'     => $order->customer_id,
            'type'           => 'debit', // customer owes money
            'amount'         => $invoice->total,
            'description'    => 'Invoice #' . $invoice->id,
            'reference_type' => 'invoice',
            'reference_id'   => $invoice->id,
        ]);
    });
}

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        //
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
