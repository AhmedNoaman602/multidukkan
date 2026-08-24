<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Payment;
use App\Services\ExpenseService;
use App\Support\LocalDateRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ReportController extends Controller
{
    public function __construct(protected ExpenseService $expenseService) {}

    public function daily(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;

        // Reports are bounded by the SHOP's trading day, not the viewer's and not UTC.
        // A daily report must show the same figures whoever opens it, so this ignores
        // X-Timezone on purpose — the one place in the app where that is correct.
        $timezone = LocalDateRange::businessTimezone();
        $from = $request->from ?? LocalDateRange::today($timezone)->toDateString();
        $to   = $request->to   ?? LocalDateRange::today($timezone)->toDateString();
        $isPrint = $request->boolean('print', false);

        $orders   = $this->fetchOrders($tenantId, $from, $to);
        $payments = $this->fetchPayments($tenantId, $from, $to, $timezone);
        $totalExpenses = $this->expenseService->getTotalForPeriod($tenantId, $from, $to);
        $perPage = $isPrint ? PHP_INT_MAX : ($request->per_page ?? 10);


       return response()->json([
            // The zone these figures are bounded by. Sent so the report pages render
            // times in the same day the totals were calculated for, instead of each
            // side deciding independently.
            'business_timezone'   => $timezone,
            'summary'             => $this->buildSummary($orders, $payments, $totalExpenses),
            'missing_cost_prices' => $this->countMissingCostPrices($orders),
            'profit_by_order'     => $this->paginate($this->buildProfitByOrder($orders),            $request->order_page    ?? 1, $perPage),
            'payments_history'    => $this->paginate($this->buildPaymentsHistory($payments),        $request->payment_page  ?? 1, $perPage),
            'orders_by_customer'  => $this->paginate($this->buildOrdersByCustomer($orders, $payments), $request->customer_page ?? 1, $perPage),
            'daily_breakdown'     => $this->paginate($this->buildDailyBreakdown($orders),           $request->daily_page    ?? 1, $perPage),
            'products_sold'       => $this->buildProductsSold($orders),
            'expenses_by_category' => $this->expenseService->getCategoryBreakdownForPeriod($tenantId, $from, $to),
        ]);
    }

// ─── Data Fetching ────────────────────────────────────────────────────────

private function fetchPayments(int $tenantId, string $from, string $to, string $timezone) : Collection {

    // paid_at is a stored UTC instant, so the local from/to dates become the UTC
    // instants bounding those local days rather than a DATE() comparison.
    return Payment::whereHas('order', fn($q) => $q->where('tenant_id', $tenantId))
    ->cashOnly()
    ->whereBetween('paid_at', [
        LocalDateRange::startOfDay($from, $timezone),
        LocalDateRange::endOfDay($to, $timezone),
    ])
    ->with(['order.items.product', 'order.customer'])
    ->get();
}

private function fetchOrders(int $tenantId, string $from, string $to): Collection
{
    // order_date is a calendar DATE column with no instant behind it — compared as
    // plain local dates, never timezone-converted.
    return Order::where('tenant_id', $tenantId)
        ->whereBetween(DB::raw('DATE(order_date)'), [$from, $to])
        ->with(['items.product', 'customer'])
        ->get();
}

    // ─── Builders ─────────────────────────────────────────────────────────────

private function buildSummary(Collection $orders, Collection $payments, float $totalExpenses): array {
    $totalRevenue   = $orders->sum(fn($o) => $this->calcOrderTotal($o));
    $totalCollected = $payments->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0));
    $grossProfit    = $orders->sum(fn($o) =>
            $o->items->sum(fn($i) =>
                ($i->unit_price - ($i->product?->cost_price ?? 0)) * $i->quantity
            )
        );

           return [
            'total_revenue'   => round($totalRevenue, 2),
            'total_collected' => round($totalCollected, 2),
            'outstanding'     => round($totalRevenue - $totalCollected, 2),
            'gross_profit'    => round($grossProfit, 2),
            'total_expenses'  => round($totalExpenses, 2),
            'net_profit'      => round($grossProfit - $totalExpenses, 2),
            'order_count'     => $orders->count(),
        ];
}

 private function buildProfitByOrder(Collection $orders): Collection
    {
        return $orders->map(function ($o) {
            $revenue = round($this->calcOrderTotal($o), 2);
            $cost    = round($o->items->sum(fn($i) => ($i->product?->cost_price ?? 0) * $i->quantity), 2);
            $profit  = round($revenue - $cost, 2);

            return [
                'invoice_number' => $o->invoice_number,
                'customer_name'  => $o->customer_name_snapshot ?? __('messages.walk_in_label'),
                'revenue'        => $revenue,
                'cost'           => $cost,
                'profit'         => $profit,
                'margin'         => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
            ];
        })->sortByDesc('profit')->values();
    }

    private function buildPaymentsHistory(Collection $payments): Collection
    {
        return $payments->map(fn($p) => [
            'invoice_number' => $p->order?->invoice_number,
            'customer_name'  => $p->order?->customer_name_snapshot ?? __('messages.walk_in_label'),
            'amount'         => round($p->amount - ($p->refunded_amount ?? 0), 2),
            'method'         => $p->method,
            'paid_at'        => $p->paid_at,
        ])
        ->filter(fn($p) => $p['amount'] > 0)
        ->sortByDesc('paid_at')->values();
    }

   private function buildOrdersByCustomer(Collection $orders, Collection $payments): Collection
    {
        return $orders->groupBy('customer_id')
            ->map(fn($customerOrders) => [
                'customer_name' => $customerOrders->first()->customer_name_snapshot
                    ?? $customerOrders->first()->customer?->name
                    ?? __('messages.walk_in_label'),
                'orders_count'  => $customerOrders->count(),
                'total'         => round($customerOrders->sum(fn($o) => $this->calcOrderTotal($o)), 2),
                'collected'     => round(
                    $payments->whereIn('order_id', $customerOrders->pluck('id'))
                        ->sum(fn($p) => $p->amount - ($p->refunded_amount ?? 0)),
                    2
                ),
            ])
            ->sortByDesc('total')
            ->values();
    }

     private function buildProductsSold(Collection $orders): Collection
    {
        return $orders->flatMap(fn($o) => $o->items)
            ->groupBy('product_name')
            ->map(fn($items, $name) => [
                'product_name' => $name,
                'units_sold'   => $items->sum('quantity'),
                'revenue'      => round($items->sum(fn($i) => $i->unit_price * $i->quantity), 2),
            ])
            ->sortByDesc('units_sold')
            ->values();
    }

      private function buildDailyBreakdown(Collection $orders): Collection
    {
        return $orders->groupBy(fn($o) => $o->order_date)
            ->map(fn($dayOrders, $date) => [
                'date'    => $date,
                'revenue' => round($dayOrders->sum(fn($o) => $this->calcOrderTotal($o)), 2),
                'orders'  => $dayOrders->count(),
            ])
            ->sortBy('date')
            ->values();
    }

 // ─── Helpers ──────────────────────────────────────────────────────────────
 private function calcOrderTotal($order): float
    {
        // orders.total is the authoritative charge amount (ADR-004) — respects manual_total overrides.
        return (float) $order->total;
    }

     private function countMissingCostPrices(Collection $orders): int
    {
        return $orders->flatMap(fn($o) => $o->items)
            ->map(fn($i) => $i->product)
            ->filter()
            ->unique('id')
            ->filter(fn($p) => is_null($p->cost_price))
            ->count();
    }

 private function paginate(Collection $collection, int $page, int $perPage): array
    {
        return [
            'data'         => $collection->forPage($page, $perPage)->values(),
            'total'        => $collection->count(),
            'per_page'     => (int) $perPage,
            'current_page' => (int) $page,
            'last_page'    => (int) ceil($collection->count() / $perPage),
        ];
    }
}