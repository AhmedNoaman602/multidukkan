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
        $payments = Payment::all();
        return response()->json($payments, 200);
    }

    public function store(StorePaymentRequest $request)
    {
        try {
            $payment = $this->payment->processPayment($request->validated());

            return response()->json($payment, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

   
}