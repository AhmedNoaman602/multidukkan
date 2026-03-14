<?php

namespace App\Services;
use App\Models\LedgerEntry;

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
   public function chargeOrder(array $data) : LedgerEntry{

    return LedgerEntry::create([
        'tenant_id' => $data['tenant_id'],
        'customer_id' => $data['customer_id'],
        'store_id' => $data['store_id'],
        'type' => 'ORDER_CHARGE',
        'amount' => $data['amount'],
        'description' => 'Order charge #'. $data['order_id'],
        'reference_type' => 'order',
        'reference_id' => $data['order_id'],
    ]);

   }

   public function applyAmount(array $data) : LedgerEntry {

    return LedgerEntry::create([
        'tenant_id' => $data['tenant_id'],
        'customer_id' => $data['customer_id'],
        'store_id' => $data['store_id'],
        'type' => 'PAYMENT',
        'amount' => $data['amount'],
        'description' => 'Payment for order #'. $data['order_id'],
        'reference_type' => 'payment',
        'reference_id' => $data['payment_id'],
    ]);

   }

   public function applyCreditOverPayment(array $data) : LedgerEntry
{
    return LedgerEntry::create([
        'tenant_id' => $data['tenant_id'],
        'customer_id' => $data['customer_id'],
        'store_id' => $data['store_id'],
        'type' => 'CREDIT_APPLY',
        'amount' => $data['amount'],
        'description' => 'overpayment credit for order #'. $data['order_id'],
        'reference_type' => 'payment',
        'reference_id' => $data['payment_id'],
    ]);
}

public function reverseOrder(array $data) : LedgerEntry {

    return LedgerEntry::create([
        'tenant_id' => $data['tenant_id'],
        'customer_id' => $data['customer_id'],
        'store_id' => $data['store_id'],
        'type' => 'REVERSAL',
        'amount' => $data['amount'],
        'description' => 'Reversal for cancelled order #'. $data['order_id'],
        'reference_type' => 'order',
        'reference_id' => $data['order_id'],
    ]);

}

public function getBalance(int $tenantId, int $customerId) : float{

    $debits = LedgerEntry::where('tenant_id', $tenantId)
    ->where('customer_id', $customerId)
    ->whereIn('type', ['ORDER_CHARGE','REVERSAL'])
    ->sum('amount');

    $credits = LedgerEntry::where('tenant_id', $tenantId)
    ->where('customer_id', $customerId)
    ->whereIn('type', ['PAYMENT', 'CREDIT_APPLY'])
    ->sum('amount');

    return round($debits - $credits, 2);
}

public function getHistory(int $tenantId, int $customerId) : \Illuminate\Support\Collection{

    return LedgerEntry::where('tenant_id', $tenantId)
    ->where('customer_id', $customerId)
    ->orderBy('created_at', 'asc')
    ->get(['id', 'type', 'amount', 'description', 'reference_type', 'reference_id', 'created_at']);
}

public function addCredit(array $data) : LedgerEntry {

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

}
