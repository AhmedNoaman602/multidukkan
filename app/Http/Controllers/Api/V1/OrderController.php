<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\OrderService;
use App\Http\Resources\OrderResource;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Requests\UpdateOrderItemRequest;
use App\Http\Requests\StoreOrderItemRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Support\LocalDateRange;
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

    // Dropdowns and the filter below all key off order_date — the business date on the
    // order, which is what "show me August" means to the shop.
    //
    // The year/month pairs are derived in PHP rather than with MONTH()/YEAR() so the
    // query stays portable (SQLite, used by the test suite, has neither function). The
    // set is bounded by distinct trading days, so this stays small.
    $orderDates = Order::where('tenant_id', $user->tenant_id)
        ->whereNotNull('order_date')
        ->distinct()
        ->orderByDesc('order_date')
        ->pluck('order_date')
        ->map(fn ($d) => substr((string) $d, 0, 10));

    $months = $orderDates
        ->map(fn ($d) => ['year' => (int) substr($d, 0, 4), 'month' => (int) substr($d, 5, 2)])
        ->unique(fn ($m) => $m['year'] . '-' . $m['month'])
        ->values();

    $years = $orderDates
        ->map(fn ($d) => (int) substr($d, 0, 4))
        ->unique()
        ->values();

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
    ->tap(fn($q) => LocalDateRange::applyCalendarDate($q, $request, 'order_date', LocalDateRange::businessTimezone()));


    $totalRevenue = (clone $query)->sum('total');
    $paidAmount = Payment::whereIn('order_id', (clone $query)->select('id'))
        ->cashOnly()
        ->sum(DB::raw('amount - COALESCE(refunded_amount, 0)'));

    $unpaidAmount = round($totalRevenue - $paidAmount, 2);

    $orders = $query->with('items.product', 'payments', 'customer')
        ->orderBy('id', 'desc')
        ->paginate(10);

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
    } catch (ValidationException $e) {
        return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
    }
}

    public function show(Request $request , Order $order)
    {
        $this->authorize('view', $order);
        
        if ($order->tenant_id != auth()->user()->tenant_id) {
        return response()->json(['message' => __('messages.unauthorized')], 403);
    }
        return new OrderResource($order->load('items.product', 'payments', 'customer'));
    }

    
    public function update(UpdateOrderRequest $request, Order $order)
    {
        $this->authorize('update', $order);


        if ($order->tenant_id != auth()->user()->tenant_id) {
        return response()->json(['message' => __('messages.unauthorized')], 403);
    }

    $validated = $request->validated();

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
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }
        
        if ($order->trashed()) {
            return response()->json(['message' => __('messages.order_already_cancelled')], 422);
        }

        try{
            $this->order->cancelOrder($order, auth()->user());

            return response()->json(['message' => __('messages.order_cancelled')], 200);
        }catch(ValidationException $e){
            return response()->json(['message' => $e->getMessage()], 422);
        } 
    }



    public function updateItem(UpdateOrderItemRequest $request, Order $order, OrderItem $item){
        $this->authorize('update', $order);

        if ($order->tenant_id != auth()->user()->tenant_id) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        if ($item->order_id !== $order->id) {
            return response()->json(['message' => __('messages.order_item_mismatch')], 404);
        }

         $this->order->adjustItem($order, $item, $request->only(['quantity', 'unit_price']));
        return new OrderResource($order->load('items.product', 'payments', 'customer'));
    }




    public function addItem(StoreOrderItemRequest $request, Order $order){
        $this->authorize('update', $order);

        if ($order->tenant_id != auth()->user()->tenant_id) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

$this->order->addItem($order, $request->validated());
return new OrderResource($order->load('items.product', 'payments', 'customer'));
    }
    
}