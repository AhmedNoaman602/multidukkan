<?php

namespace App\Services;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use App\Services\LedgerService;
use Illuminate\Validation\ValidationException;
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

    if ((int) $order->supplier_id !== (int) $supplierId) {
        throw ValidationException::withMessages([
            'purchase_order_id' => 'This purchase order does not belong to the selected supplier.'
        ]);
    }

    $orderOwed = round($order->total - $order->supplierPayments->sum('amount'), 2);

    if ($remaining > $orderOwed) {
    throw ValidationException::withMessages([
        'amount' => "Payment amount ({$remaining} EGP) exceeds the amount owed on this order ({$orderOwed} EGP)."
    ]);
}

    $applyAmount = $remaining;

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
        'invoice_number'   => $order->invoice_number,
        'user_id'           => $user->id,
    ]);

    return [$payment];
}

            $PurchaseOrders = PurchaseOrder::where('supplier_id', $supplierId)
    ->where('tenant_id', $user->tenant_id)
    ->whereUnpaid()
    ->with('supplierPayments')
    ->orderBy('created_at', 'asc')
    ->get();

    $totalOwed = round($PurchaseOrders->sum(fn($o) => $o->total - $o->supplierPayments->sum('amount')), 2);

if ($remaining > $totalOwed) {
    throw ValidationException::withMessages([
        'amount' => "Payment amount ({$remaining} EGP) exceeds the total amount owed to this supplier ({$totalOwed} EGP)."
    ]);
}

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
            'invoice_number'=> $order->invoice_number,
            'user_id' => $user->id,
        ]);

        $remaining = round($remaining - $applyAmount, 2);
        $payments[] = $payment;
    }

    return $payments;
            
        });
    }

    /**
     * Reverse (void) a supplier payment entirely — no partial-refund concept exists
     * for supplier payments. Posts a SUPPLIER_PAYMENT_REVERSAL ledger entry for the
     * full amount, then removes the payment record — mirrors how OrderService::cancelOrder
     * hard-deletes credit Payment rows once their ledger effect has been reversed;
     * the ledger entries are what balance math depends on, not the payment row itself.
     */
    public function reversePayment(SupplierPayment $payment, User $user): void
    {
        DB::transaction(function () use ($payment, $user) {
            $this->ledger->reverseSupplierPayment([
                'tenant_id'      => $payment->tenant_id,
                'supplier_id'    => $payment->supplier_id,
                'payment_id'     => $payment->id,
                'amount'         => $payment->amount,
                'invoice_number' => $payment->purchaseOrder?->invoice_number,
                'user_id'        => $user->id,
            ]);

            $payment->delete();
        });
    }
}
