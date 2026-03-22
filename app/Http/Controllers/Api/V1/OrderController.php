<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\OrderService;
use App\Http\Resources\OrderResource;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $order
    ) {}

    public function index(Request $request)
    {
        $request->validate([
            'tenant_id' => 'required|exists:tenants,id'
        ]);

        $orders = Order::where('tenant_id', $request->tenant_id)->get();

        return OrderResource::collection($orders->load('items', 'payments'));
    }

   public function store(StoreOrderRequest $request)
{
    try {
        $order = $this->order->createOrder($request->validated());
        return (new OrderResource($order->load('items', 'payments')))
            ->response()
            ->setStatusCode(201);
    } catch (\InvalidArgumentException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }
}

    public function show(Request $request , Order $order)
    {
         if ($order->tenant_id != $request->tenant_id) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }
        return new OrderResource($order->load('items', 'payments'));
    }

    public function update(Request $request, Order $order)
    {
        // Cannot Modify Order After Payment
        if ($order->tenant_id != $request->tenant_id) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    if ($order->payments()->exists()) {
        return response()->json([
            'message' => 'Cannot modify an order that has payments.'
        ], 422);
    }

        // $order->update($request->validated());

        return new OrderResource($order->load('items', 'payments'));
    }

    public function destroy(Request $request, Order $order)
    {
        
        if ($order->trashed()) {
            return response()->json(['message' => 'Order already cancelled'], 422);
        }

        if ($order->tenant_id != $request->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $this->order->cancelOrder($order);

        return response()->json(['message' => 'Order cancelled and ledger reversed successfully'], 200);
    }
}