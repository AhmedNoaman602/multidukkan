<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Product;
use App\Models\Order;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(protected LedgerService $ledger) {}

    public function index()
    {
        $tenants = Tenant::with('stores')->get();
        return view('index', compact('tenants'));
    }

    public function products()
    {
        $tenants = Tenant::all();
        $products = Product::with('tenant')->latest()->get();
        return view('products', compact('tenants', 'products'));
    }

    public function orders()
    {
        $tenants = Tenant::with('stores')->get();
        $orders = Order::with(['tenant', 'store', 'customer'])->latest()->get();
        $customers = Customer::all();
        $products = Product::all();
        return view('orders', compact('tenants', 'orders', 'customers', 'products'));
    }

    public function ledger(Customer $customer)
    {
        $balance = $this->ledger->getBalance($customer->tenant_id, $customer->id);
        $history = $this->ledger->getHistory($customer->tenant_id, $customer->id);
        return view('ledger', compact('customer', 'balance', 'history'));
    }
}
