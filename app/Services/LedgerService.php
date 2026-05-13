<?php

namespace App\Services;
use App\Models\LedgerEntry;
use App\Models\PurchaseOrder;
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

public function reversePurchaseOrder (array $data) : LedgerEntry {

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
    ]);

}

public function getBalance(int $tenantId, int $customerId) : float{

    $debits = LedgerEntry::where('tenant_id', $tenantId)
    ->where('customer_id', $customerId)
    ->whereIn('type', ['ORDER_CHARGE','REVERSAL' , 'CREDIT_CONSUMED'])
    ->sum('amount');

    $credits = LedgerEntry::where('tenant_id', $tenantId)
    ->where('customer_id', $customerId)
    ->whereIn('type', ['PAYMENT', 'CREDIT_APPLY'])
    ->sum('amount');

    return round($debits - $credits, 2);
}

public function getSupplierBalance(int $tenantId, int $supplierId): float
{
    $debits = LedgerEntry::where('tenant_id', $tenantId)
        ->where('entity_type', 'supplier')
        ->where('entity_id', $supplierId)
        ->where('direction', 'debit')
        ->sum('amount');

    $credits = LedgerEntry::where('tenant_id', $tenantId)
        ->where('entity_type', 'supplier')
        ->where('entity_id', $supplierId)
        ->where('direction', 'credit')
        ->sum('amount');

    return round($debits - $credits, 2);
}

public function getHistory(int $tenantId, ?int $customerId = null, ?int $supplierId = null): \Illuminate\Support\Collection
{
    $query = LedgerEntry::where('tenant_id', $tenantId);

    if ($supplierId) {
        $query->where('entity_type', 'supplier')
              ->where('entity_id', $supplierId);
    } else {
        $query->where('customer_id', $customerId);
    }

    return $query->orderBy('created_at', 'asc')
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

public function consumeCredit(array $data): LedgerEntry
{
    return LedgerEntry::create([
        'tenant_id'      => $data['tenant_id'],
        'customer_id'    => $data['customer_id'],
        'store_id'       => $data['store_id'],
        'type'           => 'CREDIT_CONSUMED',
        'amount'         => $data['amount'],
        'description'    => 'Credit applied to order #' . $data['order_id'],
        'reference_type' => 'payment',
        'reference_id'   => $data['payment_id'],
    ]);
}

}
