<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrdersListSettledTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Customer $customer;
    protected Product $product;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price'     => 60,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $shelf = Warehouse::factory()->shelf()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $shelf->id,
            'product_id'   => $this->product->id,
            'quantity'     => 100,
        ]);
    }

    private function order(float $total, ?string $invoice = null): Order
    {
        return Order::factory()->create(array_filter([
            'tenant_id'      => $this->tenant->id,
            'store_id'       => $this->store->id,
            'customer_id'    => $this->customer->id,
            'total'          => $total,
            'invoice_number' => $invoice,
        ], fn ($v) => $v !== null));
    }

    private function pay(Order $order, float $amount, array $attributes = [], ?string $state = null): Payment
    {
        $factory = Payment::factory();
        if ($state) {
            $factory = $factory->{$state}();
        }

        return $factory->create(array_merge([
            'tenant_id'   => $this->tenant->id,
            'order_id'    => $order->id,
            'customer_id' => $this->customer->id,
            'amount'      => $amount,
            'paid_at'     => now(),
        ], $attributes));
    }

    private function stats(array $query = []): array
    {
        return $this->actingAs($this->user)
            ->getJson('/api/orders?' . http_build_query($query))
            ->assertOk()
            ->json('stats');
    }

    public function test_an_order_paid_from_credit_counts_as_settled_not_unpaid(): void
    {
        $this->actingAs($this->user)->postJson('/api/payments/auto', [
            'customer_id' => $this->customer->id,
            'amount'      => 100,
            'method'      => 'cash',
        ])->assertStatus(201);

        $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $stats = $this->stats();

        $this->assertEquals(60, $stats['total_revenue']);
        $this->assertEquals(60, $stats['paid_amount']);
        $this->assertEquals(0, $stats['unpaid_amount']);
    }

    public function test_a_partly_paid_order_splits_between_settled_and_unpaid(): void
    {
        $this->pay($this->order(100), 30);

        $stats = $this->stats();

        $this->assertEquals(30, $stats['paid_amount']);
        $this->assertEquals(70, $stats['unpaid_amount']);
    }

    public function test_a_refunded_cash_payment_brings_back_the_unpaid_part(): void
    {
        $this->pay($this->order(100), 100, ['refunded_amount' => 40]);

        $stats = $this->stats();

        $this->assertEquals(60, $stats['paid_amount']);
        $this->assertEquals(40, $stats['unpaid_amount']);
    }

    public function test_an_order_settled_by_a_return_offset_counts_as_settled(): void
    {
        $this->pay($this->order(100), 100, [], 'returnOffset');

        $stats = $this->stats();

        $this->assertEquals(100, $stats['paid_amount']);
        $this->assertEquals(0, $stats['unpaid_amount']);
    }

    public function test_an_overpaid_order_is_capped_so_settled_plus_unpaid_equals_revenue(): void
    {
        $this->pay($this->order(100), 130);
        $this->order(50);

        $stats = $this->stats();

        $this->assertEquals(150, $stats['total_revenue']);
        $this->assertEquals(100, $stats['paid_amount']);
        $this->assertEquals(50, $stats['unpaid_amount']);
        $this->assertEquals($stats['total_revenue'], $stats['paid_amount'] + $stats['unpaid_amount']);
    }

    public function test_the_search_filter_applies_to_all_three_figures(): void
    {
        $this->pay($this->order(100, '2026-901'), 40);
        $this->pay($this->order(200, '2026-902'), 200);

        $stats = $this->stats(['search' => '2026-901']);

        $this->assertEquals(100, $stats['total_revenue']);
        $this->assertEquals(40, $stats['paid_amount']);
        $this->assertEquals(60, $stats['unpaid_amount']);
    }

    public function test_the_totals_are_computed_in_one_query(): void
    {
        $this->pay($this->order(100), 30);
        $this->pay($this->order(50), 50, [], 'credit');
        $this->actingAs($this->user);

        DB::enableQueryLog();
        $totals = app(LedgerService::class)->orderPaymentTotals(Order::query());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertEquals(['total' => 150.0, 'paid' => 80.0, 'unpaid' => 70.0], $totals);
    }
}
