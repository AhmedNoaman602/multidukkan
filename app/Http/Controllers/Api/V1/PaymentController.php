<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Services\PaymentService;
use App\Models\Payment;
use Illuminate\Http\Request;
class PaymentController extends Controller
{
    public function __construct(protected PaymentService $payment) {}

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
        ->with('customer:id,name', 'order:id,store_id')
        ->orderBy('created_at', 'desc')
        ->get();

    return response()->json([
        'data'  => $payments,
        'total' => $payments->sum('amount'),
        'count' => $payments->count(),
    ]);
}


    public function autoPayment(Request $request){

         $this->authorize('create', Payment::class);

        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'amount'      => 'required|numeric|min:0.01',
            'method'      => 'required|in:cash,card',
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