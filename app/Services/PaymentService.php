<?php

namespace App\Services;
use App\Models\Payment;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Order;
use Illuminate\Validation\ValidationException;
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

    //extracting a private distributeAmount method that both use

    public function processDirectPayment(array $data , User $user) : Payment
    {
        // Wrap the entire direct payment flow in a database transaction to guarantee data integrity.
        // If any step fails, all db updates (payment record, ledger entries, FIFO distribution) will roll back.
        return DB::transaction(function () use ($data , $user) {
            // Retrieve the order being paid, eagerly loading its items and past payments.
            $order = Order::with('items', 'payments')->findOrFail($data['order_id']);
            
            // Calculate the total cost of the order and the sum of all payments already made towards it (excluding refunded amounts).
$discount = (float) ($order->discount ?? 0);
$orderTotal = max(0, round(
    $order->items->sum(fn($i) => $i->unit_price * $i->quantity) - $discount,
    2
));            $totalAlreadyPaid = $order->payments->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0));

            // If the order has already been fully paid off, reject any new direct payment attempts.
            if($totalAlreadyPaid >= $orderTotal){
                throw new \ValidationException('Order is already fully paid.');
            }

            // Calculate how much is left to pay on the order, how much of the new payment applies to it,
            // and determine if the customer paid an excess amount that exceeds the remaining balance.
            $remaining     = max(0, $orderTotal - $totalAlreadyPaid);
            $appliedAmount = min($data['amount'], $remaining);
            $excess        = max(0, round($data['amount'] - $remaining, 2));

            // Create a payment record for the portion of the payment that is applied to the current order.
            $payment = Payment::create([
                'tenant_id'   => $user->tenant_id,
                'order_id'    => $data['order_id'],
                'customer_id' => $data['customer_id'],
                'amount'      => $appliedAmount,
                'method'      => $data['method'],
                'payment_reference'  => $data['payment_reference'] ?? null,
                'paid_at'     => now(),
            ]);

            // Register this payment amount in the customer's ledger as applied to this order.
            $this->ledger->applyAmount([
                'tenant_id'   => $user->tenant_id,
                'order_id' =>    $payment->order_id,
                'customer_id' => $payment->customer_id,
                'store_id'    => $user->store_id ?? $payment->order->store_id,
                'payment_id'  => $payment->id,
                'amount'      => $appliedAmount,
                'invoice_number' => $order->invoice_number,
            ]);

            // If there is excess money left over, try to distribute it to other unpaid orders.
            if ($excess > 0) {
                // Apply excess to older unpaid orders using First-In-First-Out (FIFO) logic.
                $leftover = $this->applyFifo(
                    excess: $excess,
                    customerId: $data['customer_id'],
                    excludeOrderId: $order->id,
                    user: $user,
                    method: $data['method'],
                    paymentReference: $data['payment_reference'] ?? null,
                );

                // If there is still money left over after trying to pay off all other unpaid orders,
                // record the remaining leftover amount as credit in the ledger.
                if ($leftover > 0) {
                    $this->ledger->applyCreditOverPayment([
                        'tenant_id'      => $user->tenant_id,
                        'customer_id'    => $payment->customer_id,
                        'store_id'       => $user->store_id ?? $payment->order->store_id,
                        'order_id'       => $payment->order_id,
                        'payment_id'     => $payment->id,
                        'amount'         => $leftover,
                        'invoice_number' => $order->invoice_number,
                    ]);
                }
            }
            return $payment;
        });
    }

    public function processAutoPayment(array $data, User $user): array
    {
        // Wrap the auto-payment flow in a transaction to make sure all created payments are saved together.
        return DB::transaction(function () use ($data, $user) {
            $customerId = $data['customer_id'];
            $remaining  = round((float) $data['amount'], 2);
            $method     = $data['method'];

            // Query all unpaid orders for this customer in chronological order (oldest first).
            // We use SQL subqueries to fetch orders where the sum of payments is less than the total item cost.
            $orders = Order::where('customer_id', $customerId)
                ->where('tenant_id', $user->tenant_id)
                ->whereColumn(
                    DB::raw('(SELECT COALESCE(SUM(amount - refunded_amount), 0) FROM payments WHERE payments.order_id = orders.id)'),
                    '<',
                    DB::raw('(SELECT COALESCE(SUM(unit_price * quantity), 0) FROM order_items WHERE order_items.order_id = orders.id)')
                )
                ->with(['items', 'payments'])
                ->orderBy('created_at', 'asc')
                ->get();

            $payments = [];

            // Iterate over each unpaid order and distribute the payment money until it's completely spent.
            foreach ($orders as $order) {
                if ($remaining <= 0) break;

                // Calculate total order cost and the total amount already paid.
$discount = (float) ($order->discount ?? 0);
$orderTotal = max(0, round(
    $order->items->sum(fn($i) => $i->unit_price * $i->quantity) - $discount, 
    2
));                $alreadyPaid = $order->payments->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0));
                $orderOwed  = round($orderTotal - $alreadyPaid, 2);

                if ($orderOwed <= 0) continue;

                // Determine how much of the remaining payment to allocate to this order.
                $applyAmount = min($remaining, $orderOwed);

                // Create the Payment record for the allocated amount.
                $payment = Payment::create([
                    'tenant_id'   => $user->tenant_id,
                    'order_id'    => $order->id,
                    'customer_id' => $customerId,
                    'amount'      => $applyAmount,
                    'method'      => $method,
                    'payment_reference'  => $data['payment_reference'] ?? null,
                    'paid_at'     => now(),
                ]);

                // Register this payment allocation in the ledger.
                $this->ledger->applyAmount([
                    'tenant_id'   => $user->tenant_id,
                    'order_id'    => $order->id,
                    'customer_id' => $customerId,
                    'store_id'    => $user->store_id ?? $order->store_id,
                    'payment_id'  => $payment->id,
                    'amount'      => $applyAmount,
                    'invoice_number' => $order->invoice_number,
                ]);

                // Deduct the allocated amount from our running payment balance.
                $remaining  = round($remaining - $applyAmount, 2);
                $payments[] = $payment;
            }

            // If we still have funds left over after paying all outstanding orders, save it as a general credit in the ledger.
            if ($remaining > 0) {
                $storeId = $user->store_id ?? ($orders->first()?->store_id);
                $this->ledger->addCredit([
                    'tenant_id'   => $user->tenant_id,
                    'customer_id' => $customerId,
                    'store_id'    => $storeId,
                    'amount'      => $remaining,
                    'description' => 'Auto-payment excess credit',
                ]);
            }

            return $payments;
        });
    }


    private function applyFifo(
        float $excess,
        int $customerId,
        int $excludeOrderId,
        User $user,
        string $method,
        ?string $paymentReference = null,
    ): float {
        // Query other unpaid orders (excluding the one that was just paid) starting from the oldest.
        $unpaidOrders = Order::where('customer_id', $customerId)
            ->where('tenant_id', $user->tenant_id)
            ->where('id', '!=', $excludeOrderId)
            ->whereColumn(
                DB::raw('(SELECT COALESCE(SUM(amount - refunded_amount), 0) FROM payments WHERE payments.order_id = orders.id)'),
                '<',
                DB::raw('(SELECT COALESCE(SUM(unit_price * quantity), 0) FROM order_items WHERE order_items.order_id = orders.id)')
            )
            ->with(['items', 'payments'])
            ->orderBy('created_at', 'asc')
            ->get();

        $remaining = round($excess, 2);

        // Distribute the excess money chronologically across these other unpaid orders.
        foreach ($unpaidOrders as $unpaidOrder) {
            if ($remaining <= 0) break;

            // Calculate the outstanding balance for this order.
$discount = (float) ($unpaidOrder->discount ?? 0);
$orderTotal = max(0, round(
    $unpaidOrder->items->sum(fn($i) => $i->unit_price * $i->quantity) - $discount,
    2
));           $alreadyPaid = $unpaidOrder->payments->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0));
            $orderOwed   = round($orderTotal - $alreadyPaid, 2);

            if ($orderOwed <= 0) continue;

            // Calculate how much we can pay off using the remaining excess.
            $applyAmount = min($remaining, $orderOwed);

            // Record a payment for this specific order.
            $fifoPayment = Payment::create([
                'tenant_id'   => $user->tenant_id,
                'order_id'    => $unpaidOrder->id,
                'customer_id' => $customerId,
                'amount'      => $applyAmount,
                'method'      => $method,
                'payment_reference'  => $paymentReference,
                'paid_at'     => now(),
            ]);

            // Register this payment in the customer's ledger.
            $this->ledger->applyAmount([
                'tenant_id'      => $user->tenant_id,
                'order_id'       => $unpaidOrder->id,
                'customer_id'    => $customerId,
                'store_id'       => $user->store_id ?? $unpaidOrder->store_id,
                'payment_id'     => $fifoPayment->id,
                'amount'         => $applyAmount,
                'invoice_number' => $unpaidOrder->invoice_number,
            ]);

            // Subtract the applied amount from our running excess total.
            $remaining = round($remaining - $applyAmount, 2);
        }

        // Return whatever excess amount remains unallocated.
        return $remaining;
    }
}
