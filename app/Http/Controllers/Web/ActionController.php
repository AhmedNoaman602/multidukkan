<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Http\Requests\StoreProductRequest;
use App\Services\OrderService;
use App\Services\PaymentService;

class ActionController extends Controller
{
    public function storeProduct(StoreProductRequest $request)
    {
        $validated = $request->validated();
        Product::create($validated);
        return redirect()->back()->with('success', 'Product created successfully!');
    }

    public function storeOrder(Request $request, OrderService $orderService)
    {
        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'store_id' => 'required|exists:stores,id',
            'customer_id' => 'required|exists:customers,id',
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $orderService->createOrder($validated);
        return redirect()->back()->with('success', 'Order created successfully!');
    }

    public function storePayment(Request $request, PaymentService $paymentService)
    {
        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'store_id' => 'required|exists:stores,id',
            'order_id' => 'required|exists:orders,id',
            'customer_id' => 'required|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string',
        ]);

        $paymentService->processPayment($validated);
        return redirect()->back()->with('success', 'Payment recorded successfully!');
    }

    public function deleteOrder(Order $order)
    {
        $order->delete(); // This should trigger reversal via observer/service
        return redirect()->back()->with('success', 'Order cancelled and reversed!');
    }
}
