<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private User $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin    = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
    }

    // $conversionFactor > 1 makes it a secondary-unit line (e.g. boxes); $unitPrice is per that unit.
    private function makeOrderWithProfit(float $total, float $unitPrice, float $costPrice, int $quantity, string $orderDate, int $conversionFactor = 1): Order
    {
        $product = Product::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'price'      => $unitPrice,
            'cost_price' => $costPrice,
        ]);

        $order = Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'total'       => $total,
            'order_date'  => $orderDate,
        ]);

        OrderItem::factory()->create([
            'order_id'          => $order->id,
            'product_id'        => $product->id,
            'quantity'          => $quantity,
            'unit_price'        => $unitPrice,
            'unit_type'         => $conversionFactor > 1 ? 'secondary' : 'base',
            'conversion_factor' => $conversionFactor,
        ]);

        return $order;
    }

    private function makeExpense(string $category, float $amount, string $expenseDate, ?int $tenantId = null): Expense
    {
        return Expense::factory()->create([
            'tenant_id'    => $tenantId ?? $this->tenant->id,
            'store_id'     => $this->store->id,
            'created_by'   => $this->admin->id,
            'category'     => $category,
            'amount'       => $amount,
            'expense_date' => $expenseDate,
        ]);
    }

    /**
     * Expense::expense_date is cast as 'date', which Eloquent serializes with a
     * "00:00:00" time component. SQLite (used in tests) stores that verbatim and
     * compares it lexicographically, so a `to` bound exactly equal to today's date
     * would wrongly exclude it ("2026-07-31 00:00:00" > "2026-07-31" as strings).
     * MySQL's DATE column type doesn't have this problem — it truncates on write.
     * Using tomorrow as the upper bound sidesteps the test-only artifact, matching
     * how ExpenseTest's own date-range test avoids landing exactly on a boundary.
     */
    private function reportUrl(string $from, ?string $to = null): string
    {
        $to ??= now()->addDay()->toDateString();

        return "/api/reports/daily?from={$from}&to={$to}";
    }

    public function test_net_profit_subtracts_expenses_in_range_from_gross_profit(): void
    {
        $today = now()->toDateString();

        // gross_profit = (200 - 80) * 3 = 360
        $this->makeOrderWithProfit(total: 600, unitPrice: 200, costPrice: 80, quantity: 3, orderDate: $today);

        $this->makeExpense('RENT', 100, $today);
        $this->makeExpense('UTILITIES', 60, $today);

        $response = $this->actingAs($this->admin)
            ->getJson($this->reportUrl($today))
            ->assertOk();

        $summary = $response->json('summary');

        $this->assertEquals(360, $summary['gross_profit']);
        $this->assertEquals(160, $summary['total_expenses']);
        $this->assertEquals(200, $summary['net_profit']);
    }

    // 2 pcs × 100, cost 60 each: subtotal 200, cost 120. Only the charged total differs.
    private function profitReport(array ...$orders): array
    {
        $today = now()->toDateString();

        foreach ($orders as $o) {
            $order = $this->makeOrderWithProfit(total: $o['total'], unitPrice: 100, costPrice: 60, quantity: 2, orderDate: $today);
            $order->update(array_diff_key($o, ['total' => true]));
        }

        return $this->actingAs($this->admin)->getJson($this->reportUrl($today))->assertOk()->json();
    }

    public function test_profit_without_discount_or_manual_total_is_unchanged(): void
    {
        $report = $this->profitReport(['total' => 200]);

        $this->assertEquals(80, $report['summary']['gross_profit']);
        $this->assertEquals([200, 120, 80], [
            $report['profit_by_order']['data'][0]['revenue'],
            $report['profit_by_order']['data'][0]['cost'],
            $report['profit_by_order']['data'][0]['profit'],
        ]);
    }

    public function test_profit_uses_the_charged_total_after_a_discount(): void
    {
        $report = $this->profitReport(['total' => 170, 'discount' => 30]);

        $this->assertEquals(50, $report['summary']['gross_profit']);
        $this->assertEquals(50, $report['profit_by_order']['data'][0]['profit']);
    }

    public function test_profit_uses_the_manual_total(): void
    {
        $report = $this->profitReport(['total' => 150, 'manual_total' => 150]);

        $this->assertEquals(30, $report['summary']['gross_profit']);
        $this->assertEquals(30, $report['profit_by_order']['data'][0]['profit']);
    }

    public function test_summary_gross_profit_equals_the_sum_of_per_order_profit(): void
    {
        $report = $this->profitReport(
            ['total' => 200],
            ['total' => 170, 'discount' => 30],
            ['total' => 150, 'manual_total' => 150],
        );

        $perOrder = collect($report['profit_by_order']['data'])->sum('profit');

        $this->assertEquals(160, $perOrder); // 80 + 50 + 30
        $this->assertEquals($perOrder, $report['summary']['gross_profit']);
        $this->assertEquals(520, $report['summary']['total_revenue']); // revenue already used orders.total
    }

    private function reportFor(callable $makeOrders): array
    {
        $makeOrders(now()->toDateString());

        return $this->actingAs($this->admin)->getJson($this->reportUrl(now()->toDateString()))->assertOk()->json();
    }

    public function test_base_unit_sale_costs_cost_price_times_pieces(): void
    {
        // 24 pcs at 10, cost 5/pc → cost 120
        $report = $this->reportFor(fn ($day) => $this->makeOrderWithProfit(total: 240, unitPrice: 10, costPrice: 5, quantity: 24, orderDate: $day));

        $this->assertEquals([240, 120, 120], [
            $report['profit_by_order']['data'][0]['revenue'],
            $report['profit_by_order']['data'][0]['cost'],
            $report['profit_by_order']['data'][0]['profit'],
        ]);
        $this->assertEquals(120, $report['summary']['gross_profit']);
    }

    public function test_secondary_unit_sale_costs_its_base_quantity(): void
    {
        // 2 boxes (1 box = 12) at 120/box, cost 5/pc → 24 pcs → cost 120, not 5 × 2 = 10
        $report = $this->reportFor(fn ($day) => $this->makeOrderWithProfit(total: 240, unitPrice: 120, costPrice: 5, quantity: 2, orderDate: $day, conversionFactor: 12));

        $this->assertEquals([240, 120, 120], [
            $report['profit_by_order']['data'][0]['revenue'],
            $report['profit_by_order']['data'][0]['cost'],
            $report['profit_by_order']['data'][0]['profit'],
        ]);
        $this->assertEquals(120, $report['summary']['gross_profit']);
    }

    public function test_mixed_unit_sales_keep_summary_and_per_order_profit_consistent(): void
    {
        $report = $this->reportFor(function ($day) {
            $this->makeOrderWithProfit(total: 240, unitPrice: 10, costPrice: 5, quantity: 24, orderDate: $day);                         // profit 120
            $this->makeOrderWithProfit(total: 240, unitPrice: 120, costPrice: 5, quantity: 2, orderDate: $day, conversionFactor: 12);  // profit 120
            $this->makeOrderWithProfit(total: 200, unitPrice: 120, costPrice: 5, quantity: 2, orderDate: $day, conversionFactor: 12)
                ->update(['discount' => 40]);                                                                                         // profit 80
        });

        $perOrder = collect($report['profit_by_order']['data'])->sum('profit');

        $this->assertEquals(320, $perOrder);
        $this->assertEquals($perOrder, $report['summary']['gross_profit']);
        $this->assertEquals(680, $report['summary']['total_revenue']);
    }

    // One product (1 box = 12) sold on today's orders; each line is [quantity, conversionFactor, unitPrice].
    private function productsSoldFor(Product $product, array ...$lines): array
    {
        foreach ($lines as [$quantity, $factor, $unitPrice]) {
            $order = Order::factory()->create([
                'tenant_id'   => $this->tenant->id,
                'store_id'    => $this->store->id,
                'customer_id' => $this->customer->id,
                'total'       => $unitPrice * $quantity,
                'order_date'  => now()->toDateString(),
            ]);
            OrderItem::factory()->create([
                'order_id'          => $order->id,
                'product_id'        => $product->id,
                'product_name'      => $product->name,
                'quantity'          => $quantity,
                'unit_price'        => $unitPrice,
                'unit_type'         => $factor > 1 ? 'secondary' : 'base',
                'conversion_factor' => $factor,
            ]);
        }

        return $this->actingAs($this->admin)
            ->getJson($this->reportUrl(now()->toDateString()))
            ->assertOk()
            ->json('products_sold');
    }

    private function boxedProduct(): Product
    {
        return Product::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'name'              => 'Water',
            'unit'              => 'pcs',
            'secondary_unit'    => 'box',
            'conversion_factor' => 12,
        ]);
    }

    public function test_units_sold_counts_a_base_unit_sale_in_pieces(): void
    {
        $sold = $this->productsSoldFor($this->boxedProduct(), [24, 1, 10]);

        $this->assertEquals([['product_name' => 'Water', 'units_sold' => 24, 'revenue' => 240]], $sold);
    }

    public function test_units_sold_counts_a_secondary_unit_sale_in_base_units(): void
    {
        $sold = $this->productsSoldFor($this->boxedProduct(), [2, 12, 120]); // 2 boxes

        $this->assertEquals(24, $sold[0]['units_sold']); // not 2
        $this->assertEquals(240, $sold[0]['revenue']);   // unchanged: unit_price × quantity
    }

    public function test_units_sold_adds_mixed_base_and_secondary_lines_in_base_units(): void
    {
        $sold = $this->productsSoldFor($this->boxedProduct(), [2, 12, 120], [5, 1, 10]); // 2 boxes + 5 pcs

        $this->assertCount(1, $sold);
        $this->assertEquals(29, $sold[0]['units_sold']); // 24 + 5, not 7
        $this->assertEquals(290, $sold[0]['revenue']);
    }

    public function test_units_sold_keeps_the_sale_time_factor_after_the_product_factor_changes(): void
    {
        $product = $this->boxedProduct();
        $this->productsSoldFor($product, [2, 12, 120]);

        $product->update(['conversion_factor' => 10]);

        $sold = $this->actingAs($this->admin)
            ->getJson($this->reportUrl(now()->toDateString()))
            ->assertOk()
            ->json('products_sold');

        $this->assertEquals(24, $sold[0]['units_sold']); // saved factor 12, not 2 × 10
    }

    public function test_net_profit_goes_negative_when_expenses_exceed_gross_profit(): void
    {
        $today = now()->toDateString();

        // gross_profit = (50 - 40) * 1 = 10
        $this->makeOrderWithProfit(total: 50, unitPrice: 50, costPrice: 40, quantity: 1, orderDate: $today);

        $this->makeExpense('SALARIES', 500, $today);

        $response = $this->actingAs($this->admin)
            ->getJson($this->reportUrl($today))
            ->assertOk();

        $summary = $response->json('summary');

        $this->assertEquals(10, $summary['gross_profit']);
        $this->assertEquals(500, $summary['total_expenses']);
        $this->assertEquals(-490, $summary['net_profit']);
    }

    public function test_expenses_outside_the_date_range_are_excluded(): void
    {
        $today = now()->toDateString();
        $outsideRange = now()->subDays(10)->toDateString();

        $this->makeExpense('RENT', 100, $today);
        $this->makeExpense('RENT', 999, $outsideRange);

        $response = $this->actingAs($this->admin)
            ->getJson($this->reportUrl($today))
            ->assertOk();

        $this->assertEquals(100, $response->json('summary.total_expenses'));
    }

    public function test_expenses_from_another_tenant_are_excluded(): void
    {
        $today = now()->toDateString();
        $otherTenant = Tenant::factory()->create();

        $this->makeExpense('RENT', 100, $today);
        $this->makeExpense('RENT', 999, $today, tenantId: $otherTenant->id);

        $response = $this->actingAs($this->admin)
            ->getJson($this->reportUrl($today))
            ->assertOk();

        $this->assertEquals(100, $response->json('summary.total_expenses'));
    }

    public function test_expenses_by_category_groups_and_sums_to_total_expenses(): void
    {
        $today = now()->toDateString();

        $this->makeExpense('RENT', 400, $today);
        $this->makeExpense('RENT', 100, $today);
        $this->makeExpense('UTILITIES', 200, $today);

        $response = $this->actingAs($this->admin)
            ->getJson($this->reportUrl($today))
            ->assertOk();

        $breakdown = collect($response->json('expenses_by_category'))->keyBy('category');

        $this->assertEquals(500, $breakdown['RENT']['total']);
        $this->assertEquals(200, $breakdown['UTILITIES']['total']);
        $this->assertEquals(
            $response->json('summary.total_expenses'),
            $breakdown->sum('total')
        );

        // Category with no spend in range never appears.
        $this->assertArrayNotHasKey('SALARIES', $breakdown->toArray());
    }

    // ── Business-timezone boundaries ─────────────────────────────────────────
    //
    // Reports are bounded by the SHOP's trading day, unlike the rest of the app which
    // follows the viewer. These tests pin that difference down, because it is the one
    // place where honouring X-Timezone would be a bug.

    /**
     * A payment at 21:30 UTC on 21 Aug is 00:30 on 22 Aug in Cairo — so it belongs to
     * the shop's 22nd, even though a viewer in UTC would call it the 21st.
     */
    private function makeCashPaymentAt(string $utc, float $amount = 250): void
    {
        $order = Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'total'       => $amount,
            'order_date'  => '2026-08-22',
        ]);

        \App\Models\Payment::factory()->create([
            'tenant_id'          => $this->tenant->id,
            'order_id'           => $order->id,
            'customer_id'        => $this->customer->id,
            'amount'             => $amount,
            'method'             => 'cash',
            'is_auto_reversible' => false,   // cashOnly() scope
            'paid_at'            => \Illuminate\Support\Carbon::parse($utc, 'UTC'),
        ]);
    }

    public function test_report_exposes_the_business_timezone_it_was_bounded_by(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson($this->reportUrl(now()->toDateString()))
            ->assertOk();

        $this->assertSame(config('app.business_timezone'), $response->json('business_timezone'));
    }

    public function test_report_day_boundaries_follow_the_shop_not_the_viewer(): void
    {
        config(['app.business_timezone' => 'Africa/Cairo']);

        // 00:30 on 22 Aug in Cairo.
        $this->makeCashPaymentAt('2026-08-21 21:30:00');

        // A viewer in UTC — who would call this instant the 21st — asking for the
        // shop's 22nd must still see it, because the shop's day is what bounds the
        // report. Under viewer-timezone boundaries this would return 0.
        $onShopDay = $this->actingAs($this->admin)
            ->withHeaders(['X-Timezone' => 'UTC'])
            ->getJson('/api/reports/daily?from=2026-08-22&to=2026-08-22')
            ->assertOk();

        $this->assertEquals(250, $onShopDay->json('summary.total_collected'));

        // And it must NOT also fall into the previous shop day.
        $onPreviousDay = $this->actingAs($this->admin)
            ->withHeaders(['X-Timezone' => 'UTC'])
            ->getJson('/api/reports/daily?from=2026-08-21&to=2026-08-21')
            ->assertOk();

        $this->assertEquals(0, $onPreviousDay->json('summary.total_collected'));
    }

    public function test_report_totals_are_identical_whatever_timezone_the_viewer_sends(): void
    {
        config(['app.business_timezone' => 'Africa/Cairo']);

        $this->makeCashPaymentAt('2026-08-21 21:30:00');

        $url = '/api/reports/daily?from=2026-08-22&to=2026-08-22';

        // Same report, three very different viewers. A business report must not change
        // depending on who opens it or where from.
        $cairo = $this->actingAs($this->admin)->withHeaders(['X-Timezone' => 'Africa/Cairo'])->getJson($url)->assertOk();
        $utc   = $this->actingAs($this->admin)->withHeaders(['X-Timezone' => 'UTC'])->getJson($url)->assertOk();
        $ny    = $this->actingAs($this->admin)->withHeaders(['X-Timezone' => 'America/New_York'])->getJson($url)->assertOk();

        $this->assertSame($cairo->json('summary'), $utc->json('summary'));
        $this->assertSame($cairo->json('summary'), $ny->json('summary'));
        $this->assertSame($cairo->json('business_timezone'), $ny->json('business_timezone'));
    }

    public function test_changing_the_configured_business_timezone_moves_the_boundary(): void
    {
        // Proves the zone is genuinely read from config rather than hardcoded anywhere.
        // The same instant belongs to the 22nd in Cairo but the 21st in UTC.
        $this->makeCashPaymentAt('2026-08-21 21:30:00');

        config(['app.business_timezone' => 'UTC']);

        $shopOnUtc = $this->actingAs($this->admin)
            ->withHeaders(['X-Timezone' => 'Africa/Cairo'])
            ->getJson('/api/reports/daily?from=2026-08-21&to=2026-08-21')
            ->assertOk();

        $this->assertSame('UTC', $shopOnUtc->json('business_timezone'));
        $this->assertEquals(250, $shopOnUtc->json('summary.total_collected'));
    }
}
