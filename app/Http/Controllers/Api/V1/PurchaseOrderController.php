<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PurchaseOrder;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Services\PurchaseOrderService;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function __construct(
        protected PurchaseOrderService $purchaseOrderService
    ) {}

    public function index(Request $request)
{
    $this->authorize('viewAny', PurchaseOrder::class);

    $user = auth()->user();

    $years = PurchaseOrder::where('tenant_id', $user->tenant_id)
        ->selectRaw('YEAR(created_at) as year')
        ->distinct()
        ->orderBy('year', 'desc')
        ->pluck('year');

        $query = PurchaseOrder::where('tenant_id', $user->tenant_id)
        ->when($request->search, function ($q) use ($request) {
            $q->where(function ($q) use ($request) {
                $q->where('invoice_number', 'like', "%$request->search%")
                  ->orWhereHas('supplier', fn($q) =>
                      $q->where('name', 'like', "%$request->search%"));
            });
        })
        ->when($request->year, fn($q) =>
            $q->whereYear('created_at', $request->year)
        )
        ->when($request->month, fn($q) =>
            $q->whereMonth('created_at', $request->month)
        )
        ->when($request->date_from, fn($q) =>
            $q->whereDate('created_at', '>=', $request->date_from)
        )
        ->when($request->date_to, fn($q) =>
            $q->whereDate('created_at', '<=', $request->date_to)
        )
        ->when($request->date_exact, fn($q) =>
            $q->whereDate('created_at', $request->date_exact)
        );

    $totalSpent = (clone $query)->sum('total');

    $paidAmount = DB::table('supplier_payments')
        ->whereIn('purchase_order_id', (clone $query)->select('id'))
        ->sum('amount');

    $unpaidAmount = max(0, round($totalSpent - $paidAmount, 2));

    $purchaseOrders = $query
        ->with('supplier', 'items.product', 'supplierPayments')
        ->orderBy('id', 'desc')
        ->paginate(10);

    return response()->json([
        'data' => PurchaseOrderResource::collection($purchaseOrders)->resolve(),
        'meta' => [
            'current_page' => $purchaseOrders->currentPage(),
            'last_page'    => $purchaseOrders->lastPage(),
            'total'        => $purchaseOrders->total(),
        ],
        'years' => $years,
        'stats' => [
            'total_orders'  => $purchaseOrders->total(),
            'total_spent'   => round($totalSpent, 2),
            'unpaid_amount' => $unpaidAmount,
            'paid_amount'   => round($paidAmount, 2),
        ],
    ]);
}

    public function store(StorePurchaseOrderRequest $request)
    {
        try {
        $this->authorize('create', PurchaseOrder::class);
        
        $purchaseOrder = $this->purchaseOrderService->createPurchaseOrder($request->validated());
        return (new PurchaseOrderResource($purchaseOrder->load('items.product', 'supplierPayments', 'supplier')))
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

        return new PurchaseOrderResource($purchaseOrder->load('supplier', 'items.product', 'supplierPayments'));
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
