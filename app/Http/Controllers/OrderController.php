<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Order;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Balance;
class OrderController extends Controller
{
    //
    public function index()
    {
       $orders = Order::latest()->get();
        $customers = Customer::all();
        $products = Product::all();
        return view('orders.index', compact('orders' , 'customers' , 'products'));
    }
    public function create()
    {
        $customers = Customer::all()->map(function($customer) {
            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'subtext' => $customer->email ?? $customer->phone,
            ];
        });

        // Pass raw products for the dynamic row selects
        $products = Product::where('stock_quantity', '>', 0)->get();

        return view('orders.create', compact('customers' , 'products'));
    }


   public function store(Request $request)
   {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
        ]);

        $subtotal = 0;
        foreach ($request->products as $item) {
            $product = Product::find($item['product_id']);
            $subtotal += $product->price * $item['quantity'];
        }

        $discount = $request->input('discount', 0);
        $total = max(0, $subtotal - $discount);

        $customer = Customer::findOrFail($request->customer_id);

        $order = Order::create([
            'order_id' => '#ORD-' . strtoupper(Str::random(5)),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'quantity' => collect($request->products)->sum('quantity'),
            'total' => $total,
            'discount_amount' => $discount,
            'payment_status' => 'unpaid',
        ]);
$lastBalance = Balance::where('customer_id', $customer->id)->latest()->first();
$currentRunningBalance = $lastBalance ? $lastBalance->running_balance : 0;
        Balance::create([
            'customer_id' => $customer->id,
            'type' => 'invoice',
            'reference' => $order->order_id,
            'description' => 'Invoice for order #' . $order->order_id,
            'amount' => $total,
            'running_balance' => $currentRunningBalance + $total,
            'payment_method' => 'cash',
        ]);

        if ($customer) {
            $customer->increment('total_orders');
            $customer->increment('total_spent', $total); 
        }

        

        foreach ($request->products as $item) {
            $product = Product::find($item['product_id']);
            $order->orderItems()->create([
                'product_id' => $product->id,
                'quantity' => $item['quantity'],
                'price' => $product->price,
                'subtotal' => $product->price * $item['quantity'],
            ]);
            
            $product->decrement('stock_quantity', $item['quantity']);
        }

        return redirect()->route('orders.index')->with('success', 'Order created successfully.');
   }
   public function show(Order $order)
   {
    $order->load(['customer' , 'orderItems.product']);
    return view('orders.show', compact('order'));
   }
}
