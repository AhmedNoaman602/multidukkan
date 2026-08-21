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
            // Must be the first query in this transaction: a locking read always returns the
            // latest committed row, letting a request that was blocked on this lock see the
            // other transaction's newly-inserted payment once it unblocks. An earlier plain
            // read here would pin an older REPEATABLE READ snapshot and hide it.
            $order = Order::lockForUpdate()->findOrFail($data['order_id']);

            // orders.total is the authoritative charge amount (ADR-004) — the sum of
            // payments already made towards it (excluding refunded amounts) is what's left to collect.
            // Freshly queried (not eager-loaded before the lock) so it reflects any payment
            // committed by a transaction we were just blocked behind.
            $orderTotal = $order->total;
            $totalAlreadyPaid = round(
                Payment::where('order_id', $order->id)->sum(DB::raw('amount - COALESCE(refunded_amount, 0)')),
                2
            );

            // If the order has already been fully paid off, reject any new direct payment attempts.
            if($totalAlreadyPaid >= $orderTotal){
                throw ValidationException::withMessages([
                    'amount' => __('messages.order_already_fully_paid'),
                ]);
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
                'user_id'     => $user->id,
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
                        'user_id'        => $user->id,
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
            // lockForUpdate() so a concurrent payment operation for the same customer (another
            // auto-payment, or a direct payment's FIFO excess distribution) blocks on these rows
            // instead of racing on stale "amount owed" figures.
            $orders = Order::where('customer_id', $customerId)
                ->where('tenant_id', $user->tenant_id)
                ->whereUnpaid()
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->get();

            $payments = [];

            // Iterate over each unpaid order and distribute the payment money until it's completely spent.
            foreach ($orders as $order) {
                if ($remaining <= 0) break;

                // Calculate total order cost and the total amount already paid. A locking read
                // (not a plain sum) so it can't reuse a snapshot pinned by an earlier read in
                // this transaction — see applyFifo for why that matters here too.
                $orderTotal = $order->total;
                $alreadyPaid = round(
                    Payment::where('order_id', $order->id)->lockForUpdate()->sum(DB::raw('amount - COALESCE(refunded_amount, 0)')),
                    2
                );
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
                    'user_id'     => $user->id,
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
                    'user_id'     => $user->id,
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
        // lockForUpdate() for the same reason as processAutoPayment: this runs nested inside
        // processDirectPayment's transaction, which already did an earlier plain read (its own
        // order's payment sum) — that pins this transaction's REPEATABLE READ snapshot, so a
        // plain sum() here would silently reuse it instead of seeing what the lock unblocks into.
        $unpaidOrders = Order::where('customer_id', $customerId)
            ->where('tenant_id', $user->tenant_id)
            ->where('id', '!=', $excludeOrderId)
            ->whereUnpaid()
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->get();

        $remaining = round($excess, 2);

        // Distribute the excess money chronologically across these other unpaid orders.
        foreach ($unpaidOrders as $unpaidOrder) {
            if ($remaining <= 0) break;

            // Calculate the outstanding balance for this order.
            $orderTotal = $unpaidOrder->total;
            $alreadyPaid = round(
                Payment::where('order_id', $unpaidOrder->id)->lockForUpdate()->sum(DB::raw('amount - COALESCE(refunded_amount, 0)')),
                2
            );
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
                'user_id'        => $user->id,
            ]);

            // Subtract the applied amount from our running excess total.
            $remaining = round($remaining - $applyAmount, 2);
        }

        // Return whatever excess amount remains unallocated.
        return $remaining;
    }
}
