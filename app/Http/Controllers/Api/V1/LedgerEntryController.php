<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Services\LedgerService;
use App\Http\Requests\StoreCreditRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Supplier;
use App\Models\Order;
use App\Models\Payment;

class LedgerEntryController extends Controller
{
     public function __construct(protected LedgerService $ledger) {}

    public function balance(Customer $customer)
    {
        $this->authorize('view', $customer);
        
        $balance = $this->ledger->getBalance($customer->tenant_id, $customer->id);

        return response()->json([
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'balance'     => $balance,
            'status'      => $balance > 0 ? 'owes' : ($balance < 0 ? 'credit' : 'settled'),
        ], 200);
    }

    public function supplierBalance(Supplier $supplier)
{
    $this->authorize('view', $supplier);

    $balance = $this->ledger->getSupplierBalance($supplier->tenant_id, $supplier->id);

    return response()->json([
        'supplier_id'   => $supplier->id,
        'supplier_name' => $supplier->name,
        'balance'       => $balance,
        'status' => $balance > 0 ? 'owes' : 'settled',
    ]);
}

    public function history(Customer $customer)
    {
        $this->authorize('view', $customer);
        
        $history = $this->ledger->getHistory($customer->tenant_id, $customer->id);
        $balance = $this->ledger->getBalance($customer->tenant_id, $customer->id);
        return response()->json([
            'customer_id'   => $customer->id,
            'customer_name' => $customer->name,
            'status'        => $balance > 0 ? 'owes' : ($balance < 0 ? 'credit' : 'settled'),
            'history' => $history,
            'balance' => $balance,
        ], 200);
    }

    public function supplierHistory(Supplier $supplier)
{
    $this->authorize('view', $supplier);

    $history = $this->ledger->getHistory($supplier->tenant_id, null, $supplier->id);
    $balance = $this->ledger->getSupplierBalance($supplier->tenant_id, $supplier->id);

    return response()->json([
        'supplier_id'   => $supplier->id,
        'supplier_name' => $supplier->name,
        'balance'       => $balance,
        'status' => $balance > 0 ? 'owes' : 'settled',
        'history'       => $history,
    ]);
}

    public function addCredit(Customer $customer, StoreCreditRequest $request)
{
    $this->authorize('addCredit', $customer);    

    $user = auth()->user();
    $entry = $this->ledger->addCredit([
        'tenant_id'   => $user->tenant_id,
        'customer_id' => $customer->id,
        'store_id'    => $user->store_id,
        'amount'      => $request->amount,
        'description' => $request->description,
        'user_id'     => $user->id,
    ]);

    $balance = $this->ledger->getBalance($user->tenant_id, $customer->id);

    return response()->json([
        'message'     => __('messages.credit_added'),
        'entry'       => $entry,
        'new_balance' => $balance,
    ], 201);
}


public function summary(Customer $customer)
{
    $this->authorize('view', $customer);

    $tenantId   = auth()->user()->tenant_id;
    $balance    = $this->ledger->getBalance($tenantId, $customer->id);
    $history    = $this->ledger->getHistory($tenantId, $customer->id);

    $orders = $customer->orders()
    ->with('payments')
    ->orderByDesc('order_date')
    ->get();

$totalOrdered  = $orders->sum('total');
$unpaidOrders = $orders->filter(fn ($o) => $o->settledAmount() < $o->total)->count();


    $payments = Payment::where('customer_id', $customer->id)
        ->whereHas('order', fn($q) => $q->where('tenant_id', $tenantId))
        ->with('order:id,invoice_number')
        ->orderByDesc('paid_at')
        ->get()
        ->map(fn($p) => [
            'id'             => $p->id,
            'amount'         => $p->amount,
            'refunded_amount'=> $p->refunded_amount ?? 0,
            'net_amount'     => $p->amount - ($p->refunded_amount ?? 0),
            'method'         => $p->method,
            'payment_reference'  => $p->payment_reference, 
            'paid_at'        => $p->paid_at,
            'invoice_number' => $p->order?->invoice_number ?? '—',
            'order_id'       => $p->order_id,
            'is_auto_reversible' => (bool) $p->is_auto_reversible,
        ]);

    return response()->json([
        'customer_id'   => $customer->id,
        'customer_name' => $customer->name,
        'balance'       => $balance,
        'status'        => $balance > 0 ? 'owes' : ($balance < 0 ? 'credit' : 'settled'),
        'stats' => [
            'total_orders'  => $orders->count(),
            'unpaid_orders' => $unpaidOrders,
            'total_ordered'   => round($totalOrdered, 2),
        ],
        'orders' => $orders->map(function ($o) {
    $total = $o->total;
    $paid            = $o->settledAmount();
    $amountRemaining = max(0, round($total - $paid, 2));

    return [
        'id'               => $o->id,
        'invoice_number'   => $o->invoice_number,
        'total'            => $total,
        'paid'             => round(min($paid, $total), 2),
        'amount_remaining' => $amountRemaining,
        'status'           => $amountRemaining > 0 ? 'unpaid' : 'paid',
        'order_date'       => $o->order_date,
        'refundable'       => $o->cashReceived(),
    ];
})->values(),
        'payments' => $payments,
        'history'  => $history,
    ]);
}
}
