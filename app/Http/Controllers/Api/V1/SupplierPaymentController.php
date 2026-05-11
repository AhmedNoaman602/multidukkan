<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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

    public function store(Request $request) {

        $this->authorize('create', SupplierPayment::class);

        $data = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'amount'      => 'required|numeric|min:0.01',
            'method'      => 'required|in:cash,bank_transfer,check',
        ]);
        try{
        $payments = $this->supplierPaymentService->processSupplierPayment($data, auth()->user());
        return response()->json([
            'message'  => 'Payment distributed across ' . count($payments) . ' order(s).',
            'payments' => $payments,           
        ], 201);
        }catch(\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        
    }
}

