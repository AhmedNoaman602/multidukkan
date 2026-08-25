<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Http\Requests\AutoPaymentRequest;
use App\Services\PaymentService;
use App\Models\Payment;
use App\Support\LocalDateRange;
use Illuminate\Http\Request;
use App\Services\LedgerService;
class PaymentController extends Controller
{
    public function __construct(protected PaymentService $payment , protected LedgerService $ledger) {}

 public function index(Request $request)
{
    $this->authorize('viewAny', Payment::class);

    $user = auth()->user();

    $payments = Payment::where('tenant_id', $user->tenant_id)
        ->when($user->store_id, function($q) use ($user) {
            $q->whereHas('order', fn($o) => $o->where('store_id', $user->store_id));
        })
        ->tap(fn($q) => LocalDateRange::apply($q, $request->merge([
            'date_exact' => $request->input('date'),
        ]), 'created_at', LocalDateRange::businessTimezone()))
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

public function store(StorePaymentRequest $request){

    $this->authorize('create', Payment::class);

    $data = $request->validated();

         try {
        $payment = $this->payment->processDirectPayment($data, auth()->user());
        return response()->json([
            'message' => __('messages.payment_processed'),
            'payment' => $payment,
        ], 201);
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }
    
}

public function update(UpdatePaymentRequest $request, Payment $payment)
{
    $this->authorize('update', $payment);

    $data = $request->validated();

    $this->ledger->adjustPayment($payment, $data['amount'], $data['method']);

    return response()->json([
        'message' => __('messages.payment_adjusted'),
        'payment' => $payment->fresh(),
    ]);
}


    public function autoPayment(AutoPaymentRequest $request){

         $this->authorize('create', Payment::class);

        $data = $request->validated();
try{
        $payments = $this->payment->processAutoPayment(
            $data,
            auth()->user()
        );
        return response()->json([
            'message'  => __('messages.payment_distributed', ['count' => count($payments)]),
            'payments' => $payments,
        ],201);
    }catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    
    }
   
}