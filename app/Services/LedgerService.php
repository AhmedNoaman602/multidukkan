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
            'reference_type' => 'supplier_payment',
            'reference_id'   => $data['payment_id'],
            'description' => 'Payment for purchase order ' . $data['invoice_number'],
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
        'type'           => 'CREDIT_APPLY',
        'amount'         => $data['amount'],
        'description'    => 'Credit restored — cancelled order ' . $data['invoice_number'],
        'reference_type' => 'order',
        'reference_id'   => $data['order_id'],
    ]);
}

    /**
     * Process a cash refund for a customer.
     * Eagerly validates that the refund amount does not exceed the total paid amount (either for a specific payment or across the whole order),
     * updates the payment records with the refunded amount, and creates a ledger refund entry.
     */
    public function issueRefund(array $data): LedgerEntry
    {
        if (!empty($data['payment_id_target'])) {
            // Case 1: Refund from a specific target payment.
            $payment = Payment::findOrFail($data['payment_id_target']);
            if ($payment->is_auto_reversible) {
                throw ValidationException::withMessages([
                    'payment' => 'Credit payments cannot be refunded as cash. Cancel the order instead.'
                ]);
            }
            $available = $payment->amount - ($payment->refunded_amount ?? 0);
            
            // Validate that we aren't refunding more than what was paid on this specific payment.
            if ($data['amount'] > $available) {
                throw ValidationException::withMessages([
                    'amount' => "Cannot refund more than {$available} EGP from this payment."
                ]);
            }
            // Increment the payment's refunded counter.
            $payment->increment('refunded_amount', $data['amount']);

        } elseif(!empty($data['order_id']))  {
            // Case 2: Refund from an entire order.
            // 1. Calculate total paid amount on this order that has not yet been refunded.
            $totalPaid = Payment::where('order_id', $data['order_id'])
                ->where('is_auto_reversible', false)
                ->sum(DB::raw('amount - COALESCE(refunded_amount, 0)'));

            // Validate that the refund request doesn't exceed the total amount paid on the order.
            if ($data['amount'] > $totalPaid) {
                throw ValidationException::withMessages([
                    'amount' => "Refund amount exceeds total paid ({$totalPaid} EGP).",
                ]);
            }

            // 2. Fetch all payments for this order and distribute the refund amount across them (FIFO order).
            $remaining = $data['amount'];
            $payments  = Payment::where('order_id', $data['order_id'])
                ->where('is_auto_reversible', false)
                ->orderBy('id', 'asc')
                ->get();

            foreach ($payments as $payment) {
                if ($remaining <= 0) break;
                $available = $payment->amount - ($payment->refunded_amount ?? 0);
                if ($available <= 0) continue;
                
                // Deduct from this payment up to its available paid amount.
                $toRefund = min($available, $remaining);
                $payment->increment('refunded_amount', $toRefund);
                $remaining -= $toRefund;
            }
        } else {
            // Throw exception if neither target payment nor order is provided.
            throw ValidationException::withMessages([
                'order_id' => 'Please select a specific order or payment to refund from.',
            ]);
        }

        // Create the REFUND type ledger entry.
        return LedgerEntry::create([
            'tenant_id'      => $data['tenant_id'],
            'customer_id'    => $data['customer_id'],
            'store_id'       => $data['store_id'],
            'type'           => 'REFUND',
            'amount'         => $data['amount'],
            'description'    => 'Cash refund — ' . ($data['notes'] ?? $data['method']),
            'reference_type' => 'payment',
            'reference_id'   => $data['payment_id'] ?? $data['customer_id'],
        ]);
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
                'amount' => 'Cannot edit a refunded payment. Reverse the refund first.'
            ]);
        }

        // Calculate other payments on this order.
        $otherPaymentsTotal = Payment::where('order_id', $payment->order_id)
            ->where('id', '!=', $payment->id)
            ->sum(DB::raw('amount - COALESCE(refunded_amount, 0)'));

        // Block setting new amount below already refunded amounts on this specific payment.
        if ($newAmount <= $payment->refunded_amount) {
            throw ValidationException::withMessages([
                'amount' => 'Cannot set amount below refunded amount.'
            ]);
        }

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
