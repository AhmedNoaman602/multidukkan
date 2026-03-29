<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Services\PaymentService;
use App\Models\Payment;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $payment) {}

    public function index()
    {
        $this->authorize('viewAny', Payment::class);
        
        $user = auth()->user();
        $payments = Payment::where('tenant_id', $user->tenant_id)
            ->when($user->store_id, fn($q) => $q->where('store_id', $user->store_id))
            ->get();
        return response()->json($payments, 200);
    }

    public function store(StorePaymentRequest $request)
    {
        try {
            $this->authorize('create', Payment::class);
            
            $payment = $this->payment->processPayment(
                $request->validated(),
                auth()->user()
            );

            return response()->json($payment, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

   
}