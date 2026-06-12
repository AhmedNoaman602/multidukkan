<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\OrderService;
use App\Http\Resources\OrderResource;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $order
    ) {}

    public function index(Request $request)
{
    $this->authorize('viewAny', Order::class);

    $user = auth()->user();

    $months = Order::where('tenant_id', $user->tenant_id)
    ->selectRaw('MONTH(created_at) as month, YEAR(created_at) as year')
    ->distinct()
    ->orderBy('year', 'desc')
    ->orderBy('month', 'desc')
    ->get();

    $years = Order::where('tenant_id', $user->tenant_id)
        ->selectRaw('YEAR(created_at) as year')
        ->distinct()
        ->orderBy('year', 'desc')
        ->pluck('year');

    // Extract base query into variable so we can clone it for stats
    $query = Order::where('tenant_id', $user->tenant_id)
    ->when($user->store_id, fn($q) => $q->where('store_id', $user->store_id))
    ->when($request->search, function ($q) use ($request) {
        $q->where(function ($q) use ($request) {
            $q->where('invoice_number', 'like', "%$request->search%")
              ->orWhereHas('customer', fn($q) =>
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


    $totalRevenue = (clone $query)->sum('total');
    $paidAmount = DB::table('payments')
    ->whereIn('order_id', (clone $query)->select('id'))
    ->sum(DB::raw('amount - COALESCE(refunded_amount, 0)'));

    $unpaidAmount = round($totalRevenue - $paidAmount, 2);

    $orders = $query->with('items', 'payments', 'customer')
        ->orderBy('id', 'desc')
        ->paginate(20);

    return response()->json([
        'data' => OrderResource::collection($orders)->resolve(),
        'meta' => [
            'current_page' => $orders->currentPage(),
            'last_page'    => $orders->lastPage(),
            'total'        => $orders->total(),
        ],
        'months' => $months,
        'years' => $years,
        'stats' => [
            'total_orders'  => $orders->total(),
            'total_revenue' => round($totalRevenue, 2),
            'unpaid_amount' => round($unpaidAmount, 2),
            'paid_amount'   => round($paidAmount, 2),
        ],
    ]);
}

   public function store(StoreOrderRequest $request)
{
    try {
        $this->authorize('create', Order::class);
        
        $order = $this->order->createOrder($request->validated());
        return (new OrderResource($order->load('items', 'payments', 'customer')))
            ->response()
            ->setStatusCode(201);
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }
}

    public function show(Request $request , Order $order)
    {
        $this->authorize('view', $order);
        
        if ($order->tenant_id != auth()->user()->tenant_id) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }
        return new OrderResource($order->load('items', 'payments', 'customer'));
    }

    public function update(Request $request, Order $order)
    {
        $this->authorize('update', $order);
        
       
        if ($order->tenant_id != auth()->user()->tenant_id) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }
    
    $validated = $request->validate([
    'notes'      => 'sometimes|nullable|string|max:500',
    'order_date' => 'sometimes|date|before_or_equal:today',
    'discount'   => 'sometimes|numeric|min:0',
]);

try {
        $order = $this->order->updateOrder($order, $validated);
        return new OrderResource($order);

    } catch (ValidationException $e) {
        return response()->json([
            'message' => $e->errors()['order'][0],
        ], 422);
    }    }

    public function destroy(Request $request, Order $order)
    {
        $this->authorize('delete', $order);

        if ($order->tenant_id != auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        if ($order->trashed()) {
            return response()->json(['message' => 'Order already cancelled'], 422);
        }

        try{
            $this->order->cancelOrder($order);

            return response()->json(['message' => 'Order cancelled and ledger reversed successfully'], 200);
        }catch(ValidationException $e){
            return response()->json(['message' => $e->getMessage()], 422);
        } 
    }

    public function updateItem(Request $request, Order $order, OrderItem $item){
        $this->authorize('update', $order);

        if ($order->tenant_id != auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

    $request->validate([
    'quantity'   => 'nullable|numeric|min:1',
    'unit_price' => 'nullable|numeric|min:0',
]);

// At least one must be present
if (!$request->quantity && !$request->unit_price) {
    return response()->json(['message' => 'Provide quantity or unit_price to update.'], 422);
}

$this->order->adjustItem($order, $item, $request->only(['quantity', 'unit_price']));
return new OrderResource($order->load('items', 'payments', 'customer'));
    }


    public function addItem(Request $request, Order $order){
        $this->authorize('update', $order);

        if ($order->tenant_id != auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

    $request->validate([
    'product_id' => 'required|exists:products,id',
    'warehouse_id' => 'required|exists:warehouses,id',
    'quantity'   => 'required|numeric|min:1',
    'unit_type'    => 'nullable|in:base,secondary',
    'unit_price' => 'nullable|numeric|min:0',
]);

$this->order->addItem($order, $request->all());
return new OrderResource($order->load('items', 'payments', 'customer'));
    }
    
}