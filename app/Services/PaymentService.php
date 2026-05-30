<?php

namespace App\Services;
use App\Models\Payment;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Order;
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

  public function processDirectPayment(array $data , User $user) : Payment
{
    return DB::transaction(function () use ($data , $user) {
        $order = Order::with('items', 'payments')->findOrFail($data['order_id']);
        $orderTotal      = $order->items->sum(fn($i) => $i->unit_price * $i->quantity); // SIMPLIFY
        $totalAlreadyPaid = $order->payments->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0));

        if($totalAlreadyPaid >= $orderTotal){
            throw new \InvalidArgumentException('Order is already fully paid.');
        }

        $remaining     = max(0, $orderTotal - $totalAlreadyPaid);
        $appliedAmount = min($data['amount'], $remaining);
        $excess        = max(0, round($data['amount'] - $remaining, 2));

        $payment = Payment::create([
            'tenant_id'   => $user->tenant_id,
            'order_id'    => $data['order_id'],
            'customer_id' => $data['customer_id'],
            'amount'      => $appliedAmount,
            'method'      => $data['method'],
            'paid_at'     => now(),
        ]);

        $this->ledger->applyAmount([
            'tenant_id'   => $user->tenant_id,
            'order_id' =>    $payment->order_id,
            'customer_id' => $payment->customer_id,
            'store_id'    => $user->store_id ?? $payment->order->store_id,
            'payment_id'  => $payment->id,
            'amount'      => $appliedAmount,
            'invoice_number' => $order->invoice_number,
        ]);

           if ($excess > 0) {
    $leftover = $this->applyFifo(
        excess: $excess,
        customerId: $data['customer_id'],
        excludeOrderId: $order->id,
        user: $user,
        method: $data['method'],
    );

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
    return DB::transaction(function () use ($data, $user) {
        $customerId = $data['customer_id'];
        $remaining  = round((float) $data['amount'], 2);
        $method     = $data['method'];

        // Load all unpaid orders oldest first
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

        foreach ($orders as $order) {
            if ($remaining <= 0) break;

            $orderTotal  = $order->items->sum(fn($i) => $i->unit_price * $i->quantity);
            $alreadyPaid = $order->payments->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0));
            $orderOwed  = round($orderTotal - $alreadyPaid, 2);

            if ($orderOwed <= 0) continue;

            $applyAmount = min($remaining, $orderOwed);

            $payment = Payment::create([
                'tenant_id'   => $user->tenant_id,
                'order_id'    => $order->id,
                'customer_id' => $customerId,
                'amount'      => $applyAmount,
                'method'      => $method,
                'paid_at'     => now(),
            ]);

            $this->ledger->applyAmount([
                'tenant_id'   => $user->tenant_id,
                'order_id'    => $order->id,
                'customer_id' => $customerId,
                'store_id'    => $user->store_id ?? $order->store_id,
                'payment_id'  => $payment->id,
                'amount'      => $applyAmount,
                'invoice_number' => $order->invoice_number,
            ]);

            $remaining  = round($remaining - $applyAmount, 2);
            $payments[] = $payment;
        }

        // Any leftover becomes credit
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
): float {
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

    foreach ($unpaidOrders as $unpaidOrder) {
        if ($remaining <= 0) break;

        $orderTotal  = $unpaidOrder->items->sum(fn($i) => $i->unit_price * $i->quantity);
        $alreadyPaid = $unpaidOrder->payments->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0));
        $orderOwed   = round($orderTotal - $alreadyPaid, 2);

        if ($orderOwed <= 0) continue;

        $applyAmount = min($remaining, $orderOwed);

        $fifoPayment = Payment::create([
            'tenant_id'   => $user->tenant_id,
            'order_id'    => $unpaidOrder->id,
            'customer_id' => $customerId,
            'amount'      => $applyAmount,
            'method'      => $method,
            'paid_at'     => now(),
        ]);

        $this->ledger->applyAmount([
            'tenant_id'      => $user->tenant_id,
            'order_id'       => $unpaidOrder->id,
            'customer_id'    => $customerId,
            'store_id'       => $user->store_id ?? $unpaidOrder->store_id,
            'payment_id'     => $fifoPayment->id,
            'amount'         => $applyAmount,
            'invoice_number' => $unpaidOrder->invoice_number,
        ]);

        $remaining = round($remaining - $applyAmount, 2);
    }

    return $remaining;
}
}
