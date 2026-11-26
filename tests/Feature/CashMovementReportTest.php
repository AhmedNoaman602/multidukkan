<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\LocalDateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The report keeps cash in and cash out on the days they happened: cash collected by
 * payment date (gross), cash refunded by refund date, customer credit paid out by payout
 * date. A later refund never lowers the day the payment was taken.
 */
class CashMovementReportTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $admin;
    protected Customer $customer;
    protected Product $product;
    protected Carbon $today;
    protected Carbon $yesterday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $shelf          = Warehouse::factory()->shelf()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        $this->admin    = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => null, 'role' => 'tenant_admin']);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 50, 'cost_price' => 10]);
        Inventory::factory()->create([
            'tenant_id' => $this->tenant->id, 'warehouse_id' => $shelf->id,
            'product_id' => $this->product->id, 'quantity' => 100,
        ]);

        // Midday on two shop days, so nothing depends on the clock's time of day.
        $tz = LocalDateRange::businessTimezone();
        $this->today     = LocalDateRange::today($tz)->setTime(12, 0);
        $this->yesterday = $this->today->copy()->subDay();
    }

    private function at(Carbon $moment, callable $action): mixed
    {
        $this->travelTo($moment->copy()->utc());
        try {
            return $action();
        } finally {
            $this->travelBack();
        }
    }

    private function sellPaid(float $cash, int $pieces = 2): Order
    {
        $id = $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => $pieces]],
        ])->assertStatus(201)->json('id');

        if ($cash > 0) {
            $this->actingAs($this->admin)->postJson('/api/payments', [
                'order_id' => $id, 'customer_id' => $this->customer->id, 'amount' => $cash, 'method' => 'cash',
            ])->assertStatus(201);
        }

        return Order::findOrFail($id);
    }

    private function summary(Carbon $day): array
    {
        $date = $day->toDateString();

        return $this->actingAs($this->admin)
            ->getJson("/api/reports/daily?from={$date}&to={$date}")
            ->assertOk()
            ->json('summary');
    }

    public function test_a_cancel_refund_shows_on_the_cancel_day_not_the_payment_day(): void
    {
        $order = $this->at($this->yesterday, fn () => $this->sellPaid(100));
        $this->at($this->today, fn () => $this->actingAs($this->admin)
            ->postJson("/api/orders/{$order->id}/cancel", ['settlement' => 'cash'])->assertOk());

        $yesterday = $this->at($this->today, fn () => $this->summary($this->yesterday));
        $this->assertEquals(100, $yesterday['total_collected'], 'The payment day keeps the cash it took.');
        $this->assertEquals(0, $yesterday['total_cash_refunded']);
        $this->assertEquals(100, $yesterday['net_cash']);

        $today = $this->at($this->today, fn () => $this->summary($this->today));
        $this->assertEquals(0, $today['total_collected']);
        $this->assertEquals(100, $today['total_cash_refunded']);
        $this->assertEquals(0, $today['total_credit_paid_out']);
        $this->assertEquals(-100, $today['net_cash']);
    }

    public function test_an_ordinary_refund_is_reported_on_its_own_day(): void
    {
        $order = $this->at($this->yesterday, fn () => $this->sellPaid(100));
        $this->at($this->today, fn () => $this->actingAs($this->admin)
            ->postJson("/api/customers/{$this->customer->id}/refund", [
                'order_id' => $order->id, 'amount' => 30, 'method' => 'cash',
            ])->assertOk());

        $yesterday = $this->at($this->today, fn () => $this->summary($this->yesterday));
        $today     = $this->at($this->today, fn () => $this->summary($this->today));

        $this->assertEquals(100, $yesterday['total_collected']);
        $this->assertEquals(0, $yesterday['total_cash_refunded']);
        $this->assertEquals(30, $today['total_cash_refunded']);
        $this->assertEquals(-30, $today['net_cash']);
    }

    public function test_a_credit_payout_is_reported_on_the_payout_day_apart_from_refunds(): void
    {
        $order = $this->at($this->yesterday, function () {
            $this->actingAs($this->admin)->postJson('/api/payments/auto', [
                'customer_id' => $this->customer->id, 'amount' => 40, 'method' => 'cash',
            ])->assertStatus(201);

            return $this->sellPaid(60);   // 40 store credit + 60 cash
        });
        $this->at($this->today, fn () => $this->actingAs($this->admin)
            ->postJson("/api/orders/{$order->id}/cancel", ['settlement' => 'cash'])->assertOk());

        $yesterday = $this->at($this->today, fn () => $this->summary($this->yesterday));
        $today     = $this->at($this->today, fn () => $this->summary($this->today));

        // Yesterday: 60 cash on the invoice and 40 settled by credit. (The 40 overpayment that
        // created the credit has no invoice, so it isn't in collected — F6, not changed here.)
        $this->assertEquals(60, $yesterday['total_collected']);
        $this->assertEquals(40, $yesterday['total_credit_settlements']);
        $this->assertEquals(0, $yesterday['total_credit_paid_out']);

        $this->assertEquals(60, $today['total_cash_refunded']);
        $this->assertEquals(40, $today['total_credit_paid_out']);
        $this->assertEquals(-100, $today['net_cash']);
    }

    public function test_a_credit_settlement_moves_no_cash(): void
    {
        $order = $this->at($this->yesterday, fn () => $this->sellPaid(100));
        $this->at($this->today, fn () => $this->actingAs($this->admin)
            ->postJson("/api/orders/{$order->id}/cancel", ['settlement' => 'credit'])->assertOk());

        $today = $this->at($this->today, fn () => $this->summary($this->today));

        $this->assertEquals(0, $today['total_cash_refunded']);
        $this->assertEquals(0, $today['total_credit_paid_out']);
        $this->assertEquals(0, $today['net_cash']);
    }
}
