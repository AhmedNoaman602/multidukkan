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
use App\Support\LocalDateRange;

class DashboardController extends Controller
{

    public function __construct(protected LedgerService $ledgerService) {}

    public function index(Request $request)
    {
        $user = auth()->user();
        $tenantId = $user->tenant_id;
        $period = $request->query('period', 'today');

        $canViewFinancials = $user->role !== 'store_staff';

        if (!in_array($period, ['today', 'week', 'month', 'year'])) {
        $period = 'today';
        }
        
    $timezone = LocalDateRange::businessTimezone();
    $localNow = now($timezone);

    $range = match ($period) {
    'today' => [
        'start' => $localNow->copy()->startOfDay(),
        'end' => $localNow->copy()->endOfDay(),
    ],
    'week' => [
        'start' => $localNow->copy()->startOfWeek(),
        'end' => $localNow->copy()->endOfWeek(),
    ],
    'month' => [
        'start' => $localNow->copy()->startOfMonth(),
        'end' => $localNow->copy()->endOfMonth(),
    ],
    'year' => [
        'start' => $localNow->copy()->startOfYear(),
        'end' => $localNow->copy()->endOfYear(),
    ],
};
        $storeScope = fn($q) => $q->when(
            $user->store_id,
            fn($q) => $q->where('store_id', $user->store_id)
        );

        $paymentStats = Payment::where('tenant_id', $tenantId)
            ->when($user->store_id, fn($q) => $q->whereHas('order', $storeScope))
            ->cashOnly()
            ->whereBetween('paid_at', [
                $range['start']->copy()->utc(),
                $range['end']->copy()->utc(),
            ])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) as total')
            ->first();

        $periodRevenue = (float) $paymentStats->total;
        $periodPaymentsCount = (int) $paymentStats->cnt;

        $recentOrders = Order::where('tenant_id', $tenantId)
            ->tap($storeScope)
            ->orderByDesc('id')
            ->limit(5)
            ->with(['payments', 'items', 'customer'])
            ->get();

        $orderStats = Order::where('tenant_id', $tenantId)
            ->tap($storeScope)
            ->whereBetween('order_date', [$range['start']->toDateString(), $range['end']->toDateString()])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(CASE WHEN total > 0 THEN total ELSE 0 END), 0) as total')
            ->first();

        $periodSales = (float) $orderStats->total;
        $periodOrdersCount = (int) $orderStats->cnt;

        $unpaidOrdersCount = Order::where('tenant_id', $tenantId)
            ->tap($storeScope)
            ->whereUnpaid()
            ->count();

         $customerIds = Customer::where('tenant_id' , $tenantId)
         ->where('is_walk_in' , false)   
         ->pluck('id')
         ->toArray();

        $totalOwed = 0;
        $topDebtors = collect();

        if ($canViewFinancials) {
            $balances = $this->ledgerService->getBalancesForCustomers($tenantId , $customerIds);

            $positiveBalances = array_filter($balances , fn($b) => $b > 0);
            $totalOwed = array_sum($positiveBalances);

            $debtorsCustomers = Customer::where('tenant_id' , $tenantId)
            ->whereIn('id' , array_keys($positiveBalances))
            ->get(['id' , 'name']);

            $unpaidCountsByCustomer = Order::where('tenant_id', $tenantId)
                ->whereIn('customer_id', $debtorsCustomers->pluck('id'))
                ->whereUnpaid()
                ->selectRaw('customer_id, COUNT(*) as cnt')
                ->groupBy('customer_id')
                ->pluck('cnt', 'customer_id');

            $topDebtors = $debtorsCustomers->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'balance' => $balances[$c->id] ?? 0,
                'unpaid_orders_count' => $unpaidCountsByCustomer[$c->id] ?? 0,
            ])->sortByDesc('balance')->take(5)->values();
        }

        $totalCustomers = count($customerIds);
        $totalProducts  = Product::where('tenant_id', $tenantId)->count();

       
        $lowStockQuery = Inventory::where('tenant_id', $tenantId)
            ->when($user->store_id, fn($q) => $q->whereHas('warehouse', $storeScope))
            ->whereColumn('quantity', '<=', 'threshold')
            ->where('quantity', '>', 0);

       
        $lowStockCount = (clone $lowStockQuery)->count();

        $lowStock = $lowStockQuery
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

        $stats = [
            'period'               => $period,
            'today_payments_count' => $periodPaymentsCount,
            'today_orders_count'   => $periodOrdersCount,
            'unpaid_orders'        => $unpaidOrdersCount,
            'total_customers'      => $totalCustomers,
            'total_products'       => $totalProducts,
            'low_stock'            => $lowStockCount,
            'can_view_financials'  => $canViewFinancials,
        ];

        if ($canViewFinancials) {
            $stats['today_revenue'] = round($periodRevenue, 2);
            $stats['today_sales']   = round($periodSales, 2);
            $stats['total_owed']    = round($totalOwed, 2);
        }

        $payload = [
            'stats'         => $stats,
            'recent_orders' => OrderResource::collection($recentOrders),
            'low_stock'     => $lowStock,
        ];

        if ($canViewFinancials) {
            $payload['top_debtors'] = $topDebtors;
        }

        return response()->json($payload);
    }
}