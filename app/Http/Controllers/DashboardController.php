<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
class DashboardController extends Controller
{
    //
    public function index()
    {
        $customers = Customer::all();    
        $orders = Order::all();
        $latestOrders = Order::latest()->limit(5)->get();
        $products = Product::all();
        return view('dashboard.index', compact('customers', 'orders', 'latestOrders', 'products'));
    }
}
