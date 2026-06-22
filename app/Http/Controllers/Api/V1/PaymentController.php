<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Services\PaymentService;
use App\Models\Payment;
use Illuminate\Http\Request;
use App\Services\LedgerService;
class PaymentController extends Controller
{
    public function __construct(protected PaymentService $payment , protected LedgerService $ledger) {}

 public function index()
{
    $this->authorize('viewAny', Payment::class);

    $user = auth()->user();

    $payments = Payment::where('tenant_id', $user->tenant_id)
        ->when($user->store_id, function($q) use ($user) {
            // Manager sees only their store's payments via order
            $q->whereHas('order', fn($o) => $o->where('store_id', $user->store_id));
        })
        ->when(request('date'), fn($q) => $q->whereDate('created_at', request('date')))
        ->when(request('year'), fn($q) => $q->whereYear('created_at', request('year')))
        ->where('method' , '!=', 'credit')
        ->with('customer:id,name', 'order:id,store_id')
        ->orderBy('created_at', 'desc')
        ->get();

    return response()->json([
        'data'  => $payments,
        'total' => $payments->sum('amount'),
        'count' => $payments->count(),
    ]);
}

public function store(Request $request){


    $this->authorize('create', Payment::class);

        $data = $request->validate([
            'order_id'    => 'required|exists:orders,id',
            'customer_id' => 'required|exists:customers,id',
            'amount'      => 'required|numeric|min:0.01',
            'method'      => 'required|in:cash,bank_transfer,instapay,vodafone_cash,orange_cash,check',
            'payment_reference' => 'nullable|string|max:255',
        ]);

         try {
        $payment = $this->payment->processDirectPayment($data, auth()->user());
        return response()->json([
            'message' => 'Payment processed successfully.',
            'payment' => $payment,
        ], 201);
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }
    
}

public function update(Request $request, Payment $payment)
{
    $this->authorize('update', $payment);

    $data = $request->validate([
        'amount' => 'required|numeric|min:0.01',
        'method' => 'required|in:cash,bank_transfer,instapay,vodafone_cash,orange_cash,check',
        'payment_reference' => 'nullable|string|max:255',
    ]);

    $this->ledger->adjustPayment($payment, $data['amount'], $data['method']);

    return response()->json([
        'message' => 'Payment adjusted successfully.',
        'payment' => $payment->fresh(),
    ]);
}


    public function autoPayment(Request $request){

         $this->authorize('create', Payment::class);

        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'amount'      => 'required|numeric|min:0.01',
            'method'      => 'required|in:cash,bank_transfer,instapay,vodafone_cash,orange_cash,check',
            'payment_reference' => 'nullable|string|max:255',
        ]);
try{
        $payments = $this->payment->processAutoPayment(
            $data,
            auth()->user()
        );
        return response()->json([
            'message'  => 'Payment distributed across ' . count($payments) . ' order(s).',
            'payments' => $payments,
        ],201);
    }catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    
    }
   
}