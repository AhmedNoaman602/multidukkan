<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Payment;

class ReportController extends Controller
{
    public function daily(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;
        $from = $request->from ?? today()->toDateString();
        $to   = $request->to   ?? today()->toDateString();

        $orders = Order::where('tenant_id', $tenantId)
            ->whereBetween('order_date', [$from, $to])
            ->with(['items.product', 'payments', 'customer'])
            ->get();

        $calcTotal = fn($o) => max(0, round(
            $o->items->sum(fn($i) => $i->unit_price * $i->quantity) - (float)($o->discount ?? 0),
            2
        ));

        $totalRevenue = $orders->sum($calcTotal);

        $totalCollected = $orders->sum(fn($o) =>
            $o->payments->where('is_auto_reversible', false)
                ->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0))
        );

        $grossProfit = $orders->sum(fn($o) =>
            $o->items->sum(fn($i) =>
                ($i->unit_price - ($i->product?->cost_price ?? 0)) * $i->quantity
            )
        );

        $missingCostPrices = $orders->flatMap(fn($o) => $o->items)
            ->map(fn($i) => $i->product)
            ->filter()
            ->unique('id')
            ->filter(fn($p) => is_null($p->cost_price))
            ->count();

        $paymentsByMethod = $orders->flatMap(fn($o) => $o->payments)
            ->where('is_auto_reversible', false)
            ->groupBy('method')
            ->map(fn($group, $method) => [
                'method' => $method,
                'total'  => round($group->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0)), 2),
                'count'  => $group->count(),
            ])->values();

        $ordersByCustomer = $orders->groupBy('customer_id')
            ->map(fn($customerOrders) => [
                'customer_name' => $customerOrders->first()->customer_name_snapshot
                    ?? $customerOrders->first()->customer?->name
                    ?? 'Walk-in',
                'orders_count'  => $customerOrders->count(),
                'total'         => round($customerOrders->sum($calcTotal), 2),
                'collected'     => round($customerOrders->sum(fn($o) =>
                    $o->payments->where('is_auto_reversible', false)
                        ->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0))
                ), 2),
            ])
            ->sortByDesc('total')
            ->values();

$productsSold = $orders->flatMap(fn($o) => $o->items)
    ->groupBy('product_name')
    ->map(fn($items, $name) => [
        'product_name' => $name,
        'units_sold'   => $items->sum('quantity'),
        'revenue'      => round($items->sum(fn($i) => $i->unit_price * $i->quantity), 2),
    ])
    ->sortByDesc('units_sold')
    ->values();

        $dailyBreakdown = $orders->groupBy(fn($o) => $o->order_date)
            ->map(fn($dayOrders, $date) => [
                'date'    => $date,
                'revenue' => round($dayOrders->sum($calcTotal), 2),
                'orders'  => $dayOrders->count(),
            ])
            ->sortBy('date')
            ->values();

        return response()->json([
            'summary' => [
                'total_revenue'   => round($totalRevenue, 2),
                'total_collected' => round($totalCollected, 2),
                'outstanding'     => round($totalRevenue - $totalCollected, 2),
                'gross_profit'    => round($grossProfit, 2),
                'order_count'     => $orders->count(),
            ],
            'missing_cost_prices' => $missingCostPrices,
            'payments_by_method' => $paymentsByMethod,
            'orders_by_customer' => $ordersByCustomer,
            'daily_breakdown'    => $dailyBreakdown,
            'products_sold' => $productsSold,
        ]);
    }
}