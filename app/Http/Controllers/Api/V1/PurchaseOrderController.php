<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PurchaseOrder;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Services\PurchaseOrderService;

class PurchaseOrderController extends Controller
{
    public function __construct(
        protected PurchaseOrderService $purchaseOrderService
    ) {}

    public function index()
    {
        $this->authorize('viewAny', PurchaseOrder::class);
        $user = auth()->user();
        $purchaseOrders = PurchaseOrder::where('tenant_id', $user->tenant_id)
            ->orderBy('created_at', 'desc')
            ->with('supplier', 'items', 'supplierPayments')
            ->get();

        return PurchaseOrderResource::collection($purchaseOrders);
    }

    public function store(StorePurchaseOrderRequest $request)
    {
        try {
        $this->authorize('create', PurchaseOrder::class);
        
        $purchaseOrder = $this->purchaseOrderService->createPurchaseOrder($request->validated());
        return (new PurchaseOrderResource($purchaseOrder->load('items', 'supplierPayments', 'supplier')))
            ->response()
            ->setStatusCode(201);
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->authorize('view', $purchaseOrder);
        $user = auth()->user();

        if ($purchaseOrder->tenant_id !== $user->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return new PurchaseOrderResource($purchaseOrder->load('supplier', 'items', 'supplierPayments'));
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder)
    {
        $this->authorize('update', $purchaseOrder);
        $user = auth()->user();
        if ($purchaseOrder->tenant_id !== $user->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $purchaseOrder->update($request->validated());
        return new PurchaseOrderResource($purchaseOrder);
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('delete', $purchaseOrder);
        
        if ($purchaseOrder->trashed()) {
            return response()->json(['message' => 'Order already cancelled'], 422);
        }

        if ($purchaseOrder->tenant_id != auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $this->purchaseOrderService->cancelPurchaseOrder($purchaseOrder);

        return response()->json(['message' => 'Order cancelled and ledger reversed successfully'], 200);
    }
}
