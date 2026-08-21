<?php

namespace App\Services;
use App\Models\LedgerEntry;
use App\Models\PurchaseOrder;
use App\Models\Payment;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
/**
 * Service for managing the financial ledger of customers and tenants.
 * 
 * This service handles all debit and credit operations, including order charges,
 * payments, reversals, and manual credits. It is responsible for calculating
 * customer balances and providing transaction history.
 * 
 * Connections:
 * - Called by {@see \App\Services\OrderService} to charge orders.
 * - Called by {@see \App\Services\PaymentService} to apply payments and overpayments.
 * - Used by {@see \App\Http\Resources\OrderResource} to resolve payment status.
 */

class LedgerService
{
    /**
     * Create a ledger entry representing a charge for a created customer order.
     */
    public function chargeOrder(array $data) : LedgerEntry
    {
        return LedgerEntry::create([
            'tenant_id' => $data['tenant_id'],
            'customer_id' => $data['customer_id'],
            'store_id' => $data['store_id'],
            'user_id' => $data['user_id'] ?? null,
            'type' => 'ORDER_CHARGE',
            'amount' => $data['amount'],
            'description' => 'Order charge ' . $data['invoice_number'],
            'reference_type' => 'order',
            'reference_id' => $data['order_id'],
        ]);
    }

    /**
     * Create a ledger entry representing a supplier charge for a purchase order.
     */
    public function purchaseCharge(array $data): LedgerEntry
    {
        return LedgerEntry::create([
            'tenant_id'      => $data['tenant_id'],
            'supplier_id'    => $data['supplier_id'],
            'entity_type'    => 'supplier',
            'entity_id'      => $data['supplier_id'],
            'direction'      => 'debit',
            'type'           => 'PURCHASE_CHARGE',
            'amount'         => $data['total'],
            'user_id'        => $data['user_id'] ?? null,
            'reference_type' => PurchaseOrder::class,
            'reference_id'   => $data['purchase_order_id'],
            'description' => 'Purchase order ' . $data['invoice_number'],
        ]);
    }

    /**
     * Create a ledger entry representing a customer payment applied to an order.
     */
    public function applyAmount(array $data) : LedgerEntry 
    {
        return LedgerEntry::create([
            'tenant_id' => $data['tenant_id'],
            'customer_id' => $data['customer_id'],
            'store_id' => $data['store_id'],
            'user_id' => $data['user_id'] ?? null,
            'type' => 'PAYMENT',
            'amount' => $data['amount'],
            'description' => 'Payment for order ' . $data['invoice_number'],
            'reference_type' => 'payment',
            'reference_id' => $data['payment_id'],
        ]);
    }
   
    /**
     * Create a ledger entry representing a payment made to a supplier for a purchase order.
     */
    public function applySupplierPayment(array $data): LedgerEntry
    {
        return LedgerEntry::create([
            'tenant_id'      => $data['tenant_id'],
            'supplier_id'    => $data['supplier_id'],
            'entity_type'    => 'supplier',
            'entity_id'      => $data['supplier_id'],
            'direction'      => 'credit',
            'type'           => 'SUPPLIER_PAYMENT',
            'amount'         => $data['amount'],
            'user_id'        => $data['user_id'] ?? null,
            'reference_type' => 'supplier_payment',
            'reference_id'   => $data['payment_id'],
            'description' => 'Payment for purchase order ' . $data['invoice_number'],
        ]);
    }

    /**
     * Reverse a supplier payment — full void only (no partial-refund concept for
     * supplier payments). Increases what's owed back to the supplier by the payment's
     * full amount, undoing what applySupplierPayment recorded.
     */
    public function reverseSupplierPayment(array $data): LedgerEntry
    {
        return LedgerEntry::create([
            'tenant_id'      => $data['tenant_id'],
            'supplier_id'    => $data['supplier_id'],
            'entity_type'    => 'supplier',
            'entity_id'      => $data['supplier_id'],
            'direction'      => 'debit',
            'type'           => 'SUPPLIER_PAYMENT_REVERSAL',
            'amount'         => $data['amount'],
            'user_id'        => $data['user_id'] ?? null,
            'reference_type' => 'supplier_payment',
            'reference_id'   => $data['payment_id'],
            'description' => 'Payment reversed' . (isset($data['invoice_number']) ? ' for purchase order ' . $data['invoice_number'] : ''),
        ]);
    }

    /**
     * Create a ledger entry representing credit generated from an overpayment on an order.
     */
    public function applyCreditOverPayment(array $data) : LedgerEntry
    {
        return LedgerEntry::create([
            'tenant_id' => $data['tenant_id'],
            'customer_id' => $data['customer_id'],
            'store_id' => $data['store_id'],
            'user_id' => $data['user_id'] ?? null,
            'type' => 'CREDIT_APPLY',
            'amount' => $data['amount'],
            'description' => 'Overpayment credit for order ' . $data['invoice_number'],
            'reference_type' => 'payment',
            'reference_id' => $data['payment_id'],
        ]);
    }

    /**
     * Create a ledger entry representing a reversal of a customer order charge when the order is cancelled.
     */
    public function reverseOrder(array $data) : LedgerEntry 
    {
        return LedgerEntry::create([
            'tenant_id' => $data['tenant_id'],
            'customer_id' => $data['customer_id'],
            'store_id' => $data['store_id'],
            'user_id' => $data['user_id'] ?? null,
            'type' => 'REVERSAL',
            'amount' => $data['amount'],
            'description' => 'Reversal for cancelled order ' . $data['invoice_number'],
            'reference_type' => 'order',
            'reference_id' => $data['order_id'],
        ]);
    }

    /**
     * Create a ledger entry representing a reversal of a supplier purchase order when cancelled.
     */
    public function reversePurchaseOrder(array $data) : LedgerEntry 
    {
        return LedgerEntry::create([
            'tenant_id'      => $data['tenant_id'],
            'supplier_id'    => $data['supplier_id'],
            'entity_type'    => 'supplier',
            'entity_id'      => $data['supplier_id'],
            'direction'      => 'credit',
            'type'           => 'PURCHASE_REVERSAL',
            'amount'         => $data['amount'],
            'user_id'        => $data['user_id'] ?? null,
            'reference_type' => PurchaseOrder::class,
            'reference_id'   => $data['purchase_order_id'],
            'description' => 'Purchase order ' . $data['invoice_number'],
        ]);
    }

    /**
     * Calculate a customer's total outstanding balance.
     * Outstanding Balance = Total Debits (charges/refunds/credits consumed) - Total Credits (payments/reversals/credit applications).
     */
    public function getBalance(int $tenantId, int $customerId) : float
    {
        // Sum all entries that increase the customer's outstanding balance (debited from customer, e.g. charges, refunds).
        $debits = LedgerEntry::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereIn('type', ['ORDER_CHARGE', 'CREDIT_CONSUMED','REFUND' ])
            ->sum('amount');

        // Sum all entries that decrease the customer's outstanding balance (credited to customer, e.g. payments, reversals).
        $credits = LedgerEntry::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereIn('type', ['PAYMENT', 'CREDIT_APPLY','REVERSAL' ])
            ->sum('amount');

        // Return the net balance rounded to 2 decimal places.
        return round($debits - $credits, 2);
    }

    /**
 * Calculate outstanding balances for multiple customers in a single batched query.
 * Returns an associative array: [customer_id => balance].
 */
public function getBalancesForCustomers(int $tenantId, array $customerIds): array
{
    $debits = LedgerEntry::where('tenant_id', $tenantId)
        ->whereIn('customer_id', $customerIds)
        ->whereIn('type', ['ORDER_CHARGE', 'CREDIT_CONSUMED', 'REFUND'])
        ->groupBy('customer_id')
        ->selectRaw('customer_id, SUM(amount) as total')
        ->pluck('total', 'customer_id');

    $credits = LedgerEntry::where('tenant_id', $tenantId)
        ->whereIn('customer_id', $customerIds)
        ->whereIn('type', ['PAYMENT', 'CREDIT_APPLY', 'REVERSAL'])
        ->groupBy('customer_id')
        ->selectRaw('customer_id, SUM(amount) as total')
        ->pluck('total', 'customer_id');

    $balances = [];
    foreach ($customerIds as $id) {
        $debit = $debits[$id] ?? 0;
        $credit = $credits[$id] ?? 0;
        $balances[$id] = round($debit - $credit, 2);
    }

    return $balances;
}

    /**
     * Calculate a supplier's total outstanding balance by summing debits and subtracting credits in their direction.
     */
    public function getSupplierBalance(int $tenantId, int $supplierId): float
    {
        // Sum all debits (charges and increases to what is owed to or by the supplier).
        $debits = LedgerEntry::where('tenant_id', $tenantId)
            ->where('entity_type', 'supplier')
            ->where('entity_id', $supplierId)
            ->where('direction', 'debit')
            ->sum('amount');

        // Sum all credits (payments and reductions to what is owed to or by the supplier).
        $credits = LedgerEntry::where('tenant_id', $tenantId)
            ->where('entity_type', 'supplier')
            ->where('entity_id', $supplierId)
            ->where('direction', 'credit')
            ->sum('amount');

        // Return the net supplier balance rounded to 2 decimal places.
        return round($debits - $credits, 2);
    }

    public function getBalancesForSuppliers(int $tenantId, array $supplierIds): array{
        $debits = LedgerEntry::where('tenant_id', $tenantId)
            ->where('entity_type', 'supplier')
            ->whereIn('entity_id', $supplierIds)
            ->where('direction', 'debit')
            ->groupBy('entity_id')
            ->selectRaw('entity_id, SUM(amount) as total')
            ->pluck('total', 'entity_id');

        $credits = LedgerEntry::where('tenant_id', $tenantId)
            ->where('entity_type', 'supplier')
            ->whereIn('entity_id', $supplierIds)
            ->where('direction', 'credit')
            ->groupBy('entity_id')
            ->selectRaw('entity_id, SUM(amount) as total')
            ->pluck('total', 'entity_id');

        $balances = [];
        foreach ($supplierIds as $id) {
            $debit = $debits[$id] ?? 0;
            $credit = $credits[$id] ?? 0;
            $balances[$id] = round($debit - $credit, 2);
        }

        return $balances;
    }

    /**
     * Fetch chronological ledger entry history for a customer or a supplier under the specified tenant.
     */
    public function getHistory(int $tenantId, ?int $customerId = null, ?int $supplierId = null): \Illuminate\Support\Collection
    {
        $query = LedgerEntry::where('tenant_id', $tenantId);

        // Filter by supplier if supplier ID is provided, otherwise filter by customer.
        if ($supplierId) {
            $query->where('entity_type', 'supplier')
                  ->where('entity_id', $supplierId);
        } else {
            $query->where('customer_id', $customerId);
        }

        // Return the sorted list of ledger columns.
        return $query->orderBy('created_at', 'asc')
            ->get(['id', 'type', 'amount', 'description', 'reference_type', 'reference_id', 'created_at']);
    }

    /**
     * Create a ledger entry representing manually applied credit for a customer.
     */
    public function addCredit(array $data) : LedgerEntry 
    {
        return LedgerEntry::create([
            'tenant_id' => $data['tenant_id'],
            'customer_id' => $data['customer_id'],
            'store_id' => $data['store_id'],
            'user_id' => $data['user_id'] ?? null,
            'type' => 'CREDIT_APPLY',
            'amount' => $data['amount'],
            'description' => $data['description'] ?? 'Manual credit',
            'reference_type' => 'manual',
            'reference_id' => $data['customer_id'],
        ]);
    }

    /**
     * Create a ledger entry representing credit consumed when credit is used to pay for an order.
     */
    public function consumeCredit(array $data): LedgerEntry
    {
        return LedgerEntry::create([
            'tenant_id'      => $data['tenant_id'],
            'customer_id'    => $data['customer_id'],
            'store_id'       => $data['store_id'],
            'user_id'        => $data['user_id'] ?? null,
            'type'           => 'CREDIT_CONSUMED',
            'amount'         => $data['amount'],
            'description'    => 'Credit applied to order ' . $data['invoice_number'],
            'reference_type' => 'payment',
            'reference_id'   => $data['payment_id'],
        ]);
    }

    /**
 * Restore credit to customer when an order paid via credit is cancelled.
 * Reverses the CREDIT_CONSUMED entry by creating a new CREDIT_APPLY entry.
 */
public function restoreCredit(array $data): LedgerEntry
{
    return LedgerEntry::create([
        'tenant_id'      => $data['tenant_id'],
        'customer_id'    => $data['customer_id'],
        'store_id'       => $data['store_id'],
        'user_id'        => $data['user_id'] ?? null,
        'type'           => 'CREDIT_APPLY',
        'amount'         => $data['amount'],
        'description'    => 'Credit restored — cancelled order ' . $data['invoice_number'],
        'reference_type' => 'order',
        'reference_id'   => $data['order_id'],
    ]);
}

    /**
     * Cash refundable balance for a whole order — net cash (non-credit) payments minus what's already refunded.
     */
    public function refundableForOrder(int $orderId): float
    {
        return round(
            Payment::where('order_id', $orderId)
                ->cashOnly()
                ->sum(DB::raw('amount - COALESCE(refunded_amount, 0)')),
            2
        );
    }

    /**
     * Cash refundable balance for a single payment. Credit (auto-reversible) payments
     * are never cash-refundable — callers must check is_auto_reversible separately.
     */
    public function refundableForPayment(Payment $payment): float
    {
        return round($payment->amount - ($payment->refunded_amount ?? 0), 2);
    }

    /**
     * Process a cash refund for a customer.
     * Eagerly validates that the refund amount does not exceed the total paid amount (either for a specific payment or across the whole order),
     * updates the payment records with the refunded amount, and creates a ledger refund entry.
     */
    public function issueRefund(array $data): LedgerEntry
    {
        return DB::transaction(function() use ($data){
            
        if (!empty($data['payment_id_target'])) {
            // Case 1: Refund from a specific target payment.
            // lockForUpdate() so a concurrent refund on this same payment blocks until
            // this transaction commits, then re-reads the current refunded_amount instead
            // of racing against a stale value.
            $payment = Payment::lockForUpdate()->findOrFail($data['payment_id_target']);
            if ($payment->is_auto_reversible) {
                throw ValidationException::withMessages([
                    'payment' => __('messages.credit_payment_no_cash_refund')
                ]);
            }
            $available = $this->refundableForPayment($payment);

            // Validate that we aren't refunding more than what was paid on this specific payment.
            if ($data['amount'] > $available) {
                throw ValidationException::withMessages([
                    'amount' => __('messages.refund_exceeds_payment', ['available' => $available])
                ]);
            }
            // Increment the payment's refunded counter.
            $payment->increment('refunded_amount', $data['amount']);

            $refType = 'payment';
            $refId   = $payment->id;

        } elseif(!empty($data['order_id']))  {
            // Case 2: Refund from an entire order.
            // Lock every candidate payment up front and derive the total from that same
            // locked set, so the availability check and the writes below see identical data.
            $payments = Payment::where('order_id', $data['order_id'])
                ->cashOnly()
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $totalPaid = round($payments->sum(fn ($p) => $this->refundableForPayment($p)), 2);

            // Validate that the refund request doesn't exceed the total amount paid on the order.
            if ($data['amount'] > $totalPaid) {
                throw ValidationException::withMessages([
                    'amount' => __('messages.refund_exceeds_total_paid', ['total' => $totalPaid]),
                ]);
            }

            // Distribute the refund amount across the locked payments (FIFO order).
            $remaining = $data['amount'];

            foreach ($payments as $payment) {
                if ($remaining <= 0) break;
                $available = $this->refundableForPayment($payment);
                if ($available <= 0) continue;
                
                // Deduct from this payment up to its available paid amount.
                $toRefund = min($available, $remaining);
                $payment->increment('refunded_amount', $toRefund);
                $remaining -= $toRefund;
            }

            $refType = 'order';
            $refId   = $data['order_id'];
        } else {
            // Throw exception if neither target payment nor order is provided.
            throw ValidationException::withMessages([
                'order_id' => __('messages.refund_select_order_or_payment'),
            ]);
        }

        // Create the REFUND type ledger entry.
        return LedgerEntry::create([
            'tenant_id'      => $data['tenant_id'],
            'customer_id'    => $data['customer_id'],
            'store_id'       => $data['store_id'],
            'user_id'        => $data['user_id'] ?? null,
            'type'           => 'REFUND',
            'amount'         => $data['amount'],
            'description'    => 'Cash refund — ' . ($data['notes'] ?? $data['method']),
            'reference_type' => $refType,
            'reference_id'   => $refId,
        ]);
        });
    }

    /**
     * Calculate the net remaining credit balance for a customer (Total Credits Applied - Total Credits Consumed).
     */
    public function getCreditBalance(int $tenantId, int $customerId): float
    {
        $credits = LedgerEntry::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereIn('type', ['CREDIT_APPLY'])
            ->sum('amount');

        $consumed = LedgerEntry::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereIn('type', ['CREDIT_CONSUMED'])
            ->sum('amount');

        return max(0, round($credits - $consumed, 2));
    }

    /**
     * Adjust a payment amount and method.
     * Validates that the payment hasn't already been refunded, updates the payment record, and modifies the ledger payment amount.
     */
    public function adjustPayment(Payment $payment, float $newAmount, string $newMethod): void
    {
        // Block adjustment if the payment has already been refunded.
        if ($payment->refunded_amount > 0) {
            throw ValidationException::withMessages([
                'amount' => __('messages.cannot_edit_refunded_payment')
            ]);
        }

        // Calculate other payments on this order.
        $otherPaymentsTotal = Payment::where('order_id', $payment->order_id)
            ->where('id', '!=', $payment->id)
            ->sum(DB::raw('amount - COALESCE(refunded_amount, 0)'));

        // Block setting new amount below already refunded amounts on this specific payment.
        if ($newAmount <= $payment->refunded_amount) {
            throw ValidationException::withMessages([
                'amount' => __('messages.amount_below_refunded')
            ]);
        }

         DB::transaction(function() use ($payment,$newAmount,$newMethod){
 // Update the payment ledger entry to match the new payment amount.
        LedgerEntry::where('reference_type', 'payment')
            ->where('reference_id', $payment->id)
            ->where('type', 'PAYMENT')
            ->update(['amount' => $newAmount]);

        // Update the payment record details.
        $payment->update([
            'amount' => $newAmount,
            'method' => $newMethod,
        ]);
        });

       
    }

    public function adjustOrderCharge(Order $order, float $newTotal): void
    {
        LedgerEntry::where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->where('type', 'ORDER_CHARGE')
            ->update(['amount' => $newTotal]);
        
        $order->update(['total' => $newTotal]);
    }
}
