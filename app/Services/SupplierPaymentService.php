<?php

namespace App\Services;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
class SupplierPaymentService
{
    /**
     * Create a new class instance.
     */
    public function __construct(protected LedgerService $ledger){}

    public function processSupplierPayment(array $data , User $user) : array {
        return DB::transaction(function () use ($data , $user) {
            $supplierId = $data['supplier_id'];
            $remaining = round((float)$data['amount'],2);
            
            // Direct payment to a specific order
if (!empty($data['purchase_order_id'])) {
    $order = PurchaseOrder::with('supplierPayments')->findOrFail($data['purchase_order_id']);
    
    $orderOwed = round($order->total - $order->supplierPayments->sum('amount'), 2);
    $applyAmount = min($remaining, $orderOwed);

    $payment = SupplierPayment::create([
        'tenant_id'         => $user->tenant_id,
        'purchase_order_id' => $order->id,
        'supplier_id'       => $supplierId,
        'amount'            => $applyAmount,
        'method'            => $data['method'],
        'paid_at'           => now(),
    ]);

    $this->ledger->applySupplierPayment([
        'tenant_id'         => $user->tenant_id,
        'purchase_order_id' => $order->id,
        'supplier_id'       => $supplierId,
        'payment_id'        => $payment->id,
        'amount'            => $applyAmount,
    ]);

    return [$payment];
}

            $PurchaseOrders = PurchaseOrder::where('supplier_id', $supplierId)
    ->where('tenant_id', $user->tenant_id)
    ->with('supplierPayments')
    ->whereColumn(
        DB::raw('(SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE supplier_payments.purchase_order_id = purchase_orders.id)'),
        '<',
        DB::raw('total')
    )
    ->orderBy('created_at', 'asc')
    ->get();

    $payments = [];
    foreach($PurchaseOrders as $order){
        if($remaining <=0) break;

        $orderTotal = $order->total;
        $alreadyPaid = $order->supplierPayments->sum('amount');
        $orderOwed = round($orderTotal - $alreadyPaid, 2);

        if($orderOwed <=0) continue;

        $applyAmount = min($remaining, $orderOwed);

        $payment = SupplierPayment::create([
            'tenant_id' => $user->tenant_id,
            'purchase_order_id' => $order->id,
            'supplier_id' => $supplierId,
            'amount' => $applyAmount,
            'method' => $data['method'],
            'paid_at' => now(),
        ]);

        $this->ledger->applySupplierPayment([
            'tenant_id' => $user->tenant_id,
            'purchase_order_id' => $order->id,
            'supplier_id' => $supplierId,
            'payment_id' => $payment->id,
            'amount' => $applyAmount,
        ]);

        $remaining = round($remaining - $applyAmount, 2);
        $payments[] = $payment;
    }

    return $payments;
            
        });
    }
}
