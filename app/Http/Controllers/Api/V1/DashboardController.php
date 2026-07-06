<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Payment;
use App\Models\Inventory;
use App\Http\Resources\OrderResource;
use App\Services\LedgerService;

class DashboardController extends Controller
{

    public function __construct(protected LedgerService $ledgerService) {}

    public function index(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;
        $today = now()->toDateString();

        // Today's payments
        $todayPayments = Payment::whereHas('order', fn($q) => $q->where('tenant_id', $tenantId))
            ->where('is_auto_reversible', false)
            ->whereDate('paid_at', $today)
            ->get();

        $todayRevenue = $todayPayments->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0));

        // Recent orders
        $recentOrders = Order::where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit(5)
            ->with(['payments', 'items', 'customer'])
            ->get();

        // Today's orders
        $todayOrders = Order::where('tenant_id', $tenantId)
            ->whereDate('order_date', $today)
            ->with('payments')
            ->get();

        $todaySales = $todayOrders->sum(fn($o) => max(0, $o->total ?? 0));

        // Unpaid orders
        $unpaidOrdersCount = Order::where('tenant_id', $tenantId)
            ->with('payments')
            ->get()
            ->filter(fn($o) =>
                $o->total > $o->payments->where('is_auto_reversible', false)
                    ->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0))
            )
            ->count();

         $customerIds = Customer::where('tenant_id' , $tenantId)
         ->where('is_walk_in' , false)   
         ->pluck('id')
         ->toArray();

        $balances = $this->ledgerService->getBalancesForCustomers($tenantId , $customerIds);

        $positiveBalances = array_filter($balances , fn($b) => $b > 0);
        $totalOwed = array_sum($positiveBalances);

        $debtorsCustomers = Customer::where('tenant_id' , $tenantId)
        ->whereIn('id' , array_keys($positiveBalances))
        ->get(['id' , 'name']);

        $unpaidCountsByCustomer = Order::where('tenant_id', $tenantId)
            ->whereIn('customer_id', $debtorsCustomers->pluck('id'))
            ->with('payments')
            ->get()
            ->filter(fn($o) =>
                $o->total > $o->payments->where('is_auto_reversible', false)
                    ->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0))
            )
            ->groupBy('customer_id')
            ->map(fn($orders) => count($orders));
        
       $topDebtors = $debtorsCustomers->map(fn($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'balance' => $balances[$c->id] ?? 0,
            'unpaid_orders_count' => $unpaidCountsByCustomer[$c->id] ?? 0,
        ])->sortByDesc('balance')->take(5)->values();

        // Counts
        $totalCustomers = count($customerIds);
        $totalProducts  = Product::where('tenant_id', $tenantId)->count();

        // Low stock
        $lowStock = Inventory::where('tenant_id', $tenantId)
            ->whereColumn('quantity', '<=', 'threshold')
            ->where('quantity', '>', 0)
            ->with('product', 'warehouse')
            ->limit(3)
            ->get()
            ->map(fn($i) => [
                'id'             => $i->id,
                'product_name'   => $i->product?->name,
                'warehouse_name' => $i->warehouse?->name,
                'quantity'       => $i->quantity,
                'threshold'      => $i->threshold,
            ]);

        return response()->json([
            'stats' => [
                'today_revenue'        => round($todayRevenue, 2),
                'today_payments_count' => $todayPayments->count(),
                'today_orders_count'   => $todayOrders->count(),
                'today_sales'          => round($todaySales, 2),
                'unpaid_orders'        => $unpaidOrdersCount,
                'total_owed'           => round($totalOwed, 2),
                'total_customers'      => $totalCustomers,
                'total_products'       => $totalProducts,
                'low_stock'            => $lowStock->count(),
            ],
            'recent_orders' => OrderResource::collection($recentOrders),
            'top_debtors'   => $topDebtors,
            'low_stock'     => $lowStock,
        ]);
    }
}