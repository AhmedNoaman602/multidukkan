<?php

namespace App\Services;
use App\Models\Payment;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use App\Models\OrderItem;
use App\Models\User;
/**
 * Service for processing payments and managing their impact on the ledger.
 * 
 * This service handles the recording of payments, calculates how much of a payment
 * is applied to a specific order, and manages any excess as a credit in the ledger.
 * 
 * Connections:
 * - Depends on {@see \App\Services\LedgerService} to apply payments and credits.
 */
class PaymentService
{
    /**
     * Create a new class instance.
     */
    public function __construct(protected LedgerService $ledger) {}

  public function processPayment(array $data , User $user) : Payment
{
    return DB::transaction(function () use ($data , $user) {

        $orderTotal = OrderItem::where('order_id', $data['order_id'])
            ->sum(DB::raw('unit_price * quantity'));

        $totalAlreadyPaid = Payment::where('order_id', $data['order_id'])
            ->sum('amount');

            if($totalAlreadyPaid >= $orderTotal){
                throw new \InvalidArgumentException('Order is already fully paid.');
            }

        $payment = Payment::create([
            'tenant_id'   => $user->tenant_id,
            'order_id'    => $data['order_id'],
            'customer_id' => $data['customer_id'],
            'amount'      => $data['amount'],
            'method'      => $data['method'],
            'paid_at'     => now(),
        ]);


        $remaining     = max(0, $orderTotal - $totalAlreadyPaid);
        $appliedAmount = min($payment->amount, $remaining);
        $excess        = max(0, round($payment->amount - $remaining, 2));

        $this->ledger->applyAmount([
            'tenant_id'   => $user->tenant_id,
            'order_id' =>    $payment->order_id,
            'customer_id' => $payment->customer_id,
            'store_id'    => $user->store_id ?? $payment->order->store_id,
            'payment_id'  => $payment->id,
            'amount'      => $appliedAmount,
        ]);

            if ($excess > 0) {
                $this->ledger->applyCreditOverPayment([
                    'tenant_id'   => $user->tenant_id,
                    'customer_id' => $payment->customer_id,
                    'store_id'    => $user->store_id ?? $payment->order->store_id,
                    'order_id'    => $payment->order_id,
                    'payment_id'  => $payment->id,
                    'amount'      => $excess,
                ]);
            }
        return $payment;
    });
}
}
