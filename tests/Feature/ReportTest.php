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

    private function makeOrderWithProfit(float $total, float $unitPrice, float $costPrice, int $quantity, string $orderDate): Order
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
            'order_id'   => $order->id,
            'product_id' => $product->id,
            'quantity'   => $quantity,
            'unit_price' => $unitPrice,
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
