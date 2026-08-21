<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\StoreSupplierPaymentRequest;
use App\Services\SupplierPaymentService;
use App\Models\SupplierPayment;

class SupplierPaymentController extends Controller
{
    public function __construct(
        protected SupplierPaymentService $supplierPaymentService
    ) {}

    public function index(){
        $this->authorize('viewAny', SupplierPayment::class);

        $user = auth()->user();

        $payments = SupplierPayment::where('tenant_id', $user->tenant_id)
        ->when(request('date'), fn($q) => $q->whereDate('created_at', request('date')))
        ->when(request('year'), fn($q) => $q->whereYear('created_at', request('year')))
        ->with('supplier:id,name', 'purchaseOrder:id,supplier_id')
        ->orderBy('created_at', 'desc')
        ->get();

        return response()->json([
            'data'  => $payments,
            'total' => $payments->sum('amount'),
            'count' => $payments->count(),
        ]);
    }

    public function store(StoreSupplierPaymentRequest $request) {

        $this->authorize('create', SupplierPayment::class);

        $data = $request->validated();
        try{
        $payments = $this->supplierPaymentService->processSupplierPayment($data, auth()->user());
        return response()->json([
            'message'  => __('messages.payment_distributed', ['count' => count($payments)]),
            'payments' => $payments,
        ], 201);
        }catch(\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

    }

    public function destroy(SupplierPayment $supplierPayment)
    {
        $this->authorize('delete', $supplierPayment);

        if ($supplierPayment->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        try {
            $this->supplierPaymentService->reversePayment($supplierPayment, auth()->user());

            return response()->json(['message' => __('messages.payment_reversed')], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}

