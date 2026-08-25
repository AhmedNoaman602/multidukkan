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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard is the first screen after login, and it used to filter by tenant_id
 * alone — a Store A manager saw Store B's revenue, orders and stock. These tests pin
 * the store boundary down, and cover the low_stock count that was capped at 3 by the
 * preview limit.
 */
class DashboardScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $storeA;
    private Store $storeB;
    private Warehouse $warehouseA;
    private Warehouse $warehouseB;
    private Customer $customer;
    private Product $product;
    private User $admin;
    private User $managerA;
    private User $staffA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->storeA = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->storeB = Store::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->warehouseA = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->storeA->id,
        ]);
        $this->warehouseB = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->storeB->id,
        ]);

        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->managerA = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->storeA->id,
            'role'      => 'store_manager',
        ]);
        $this->staffA = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->storeA->id,
            'role'      => 'store_staff',
        ]);
    }

    /** A paid order on today's business day, at the given store. */
    private function paidOrderAt(Store $store, float $amount): Order
    {
        $order = Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $store->id,
            'customer_id' => $this->customer->id,
            'total'       => $amount,
            'order_date'  => now(config('app.business_timezone'))->toDateString(),
        ]);

        Payment::factory()->create([
            'tenant_id'          => $this->tenant->id,
            'order_id'           => $order->id,
            'customer_id'        => $this->customer->id,
            'amount'             => $amount,
            'method'             => 'cash',
            'is_auto_reversible' => false,
            'paid_at'            => Carbon::now('UTC'),
        ]);

        return $order;
    }

    /** An unpaid order (no payment rows at all) at the given store. */
    private function unpaidOrderAt(Store $store, float $amount): Order
    {
        return Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $store->id,
            'customer_id' => $this->customer->id,
            'total'       => $amount,
            'order_date'  => now(config('app.business_timezone'))->toDateString(),
        ]);
    }

    private function lowStockIn(Warehouse $warehouse, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Inventory::factory()->create([
                'tenant_id'    => $this->tenant->id,
                'warehouse_id' => $warehouse->id,
                'product_id'   => Product::factory()->create(['tenant_id' => $this->tenant->id])->id,
                'quantity'     => 2,
                'threshold'    => 5,
            ]);
        }
    }

    public function test_store_manager_sees_only_their_own_stores_revenue_and_orders(): void
    {
        $this->paidOrderAt($this->storeA, 100);
        $this->paidOrderAt($this->storeB, 900);

        $stats = $this->actingAs($this->managerA)
            ->getJson('/api/dashboard?period=today')
            ->assertStatus(200)
            ->json('stats');

        $this->assertEquals(100, $stats['today_revenue']);
        $this->assertEquals(1, $stats['today_payments_count']);
        $this->assertEquals(100, $stats['today_sales']);
        $this->assertEquals(1, $stats['today_orders_count']);
    }

    public function test_tenant_admin_still_sees_every_store(): void
    {
        $this->paidOrderAt($this->storeA, 100);
        $this->paidOrderAt($this->storeB, 900);

        $stats = $this->actingAs($this->admin)
            ->getJson('/api/dashboard?period=today')
            ->assertStatus(200)
            ->json('stats');

        $this->assertEquals(1000, $stats['today_revenue']);
        $this->assertEquals(2, $stats['today_payments_count']);
        $this->assertEquals(1000, $stats['today_sales']);
        $this->assertEquals(2, $stats['today_orders_count']);
    }

    public function test_unpaid_order_count_is_scoped_to_the_store(): void
    {
        $this->unpaidOrderAt($this->storeA, 50);
        $this->unpaidOrderAt($this->storeB, 50);
        $this->unpaidOrderAt($this->storeB, 50);

        $this->assertEquals(1, $this->actingAs($this->managerA)
            ->getJson('/api/dashboard')->json('stats.unpaid_orders'));

        $this->assertEquals(3, $this->actingAs($this->admin)
            ->getJson('/api/dashboard')->json('stats.unpaid_orders'));
    }

    public function test_recent_orders_never_include_another_store(): void
    {
        $mine    = $this->paidOrderAt($this->storeA, 100);
        $notMine = $this->paidOrderAt($this->storeB, 900);

        $ids = collect($this->actingAs($this->managerA)
            ->getJson('/api/dashboard')
            ->assertStatus(200)
            ->json('recent_orders'))
            ->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($notMine->id));
    }

    public function test_low_stock_is_scoped_to_the_stores_warehouses(): void
    {
        $this->lowStockIn($this->warehouseA, 2);
        $this->lowStockIn($this->warehouseB, 4);

        $response = $this->actingAs($this->managerA)
            ->getJson('/api/dashboard')
            ->assertStatus(200);

        $this->assertEquals(2, $response->json('stats.low_stock'));

        foreach ($response->json('low_stock') as $row) {
            $this->assertSame($this->warehouseA->name, $row['warehouse_name']);
        }
    }

    /** The count used to be taken from a collection capped at limit(3). */
    public function test_low_stock_count_is_not_capped_by_the_preview_limit(): void
    {
        $this->lowStockIn($this->warehouseA, 7);

        $response = $this->actingAs($this->managerA)
            ->getJson('/api/dashboard')
            ->assertStatus(200);

        $this->assertEquals(7, $response->json('stats.low_stock'));
        $this->assertCount(3, $response->json('low_stock'));
    }

    public function test_staff_never_receive_revenue_debt_or_the_debtor_list(): void
    {
        $this->paidOrderAt($this->storeA, 100);
        $this->unpaidOrderAt($this->storeA, 400);

        $response = $this->actingAs($this->staffA)
            ->getJson('/api/dashboard?period=today')
            ->assertStatus(200);

        $response->assertJsonMissingPath('stats.today_revenue')
            ->assertJsonMissingPath('stats.today_sales')
            ->assertJsonMissingPath('stats.total_owed')
            ->assertJsonMissingPath('top_debtors');

        $this->assertFalse($response->json('stats.can_view_financials'));

        // The non-financial half of the dashboard still works for them.
        $this->assertEquals(2, $response->json('stats.today_orders_count'));
        $this->assertEquals(1, $response->json('stats.unpaid_orders'));
    }

    public function test_manager_and_admin_still_receive_the_financials(): void
    {
        $this->paidOrderAt($this->storeA, 100);

        foreach ([$this->managerA, $this->admin] as $user) {
            $response = $this->actingAs($user)
                ->getJson('/api/dashboard?period=today')
                ->assertStatus(200);

            $this->assertTrue($response->json('stats.can_view_financials'));
            $this->assertEquals(100, $response->json('stats.today_revenue'));
            $response->assertJsonStructure(['stats' => ['total_owed'], 'top_debtors']);
        }
    }

    /** SUM must clamp per order, as the old max(0, ...) did — not after summing. */
    public function test_negative_order_totals_are_clamped_per_order(): void
    {
        $this->unpaidOrderAt($this->storeA, 100);
        $this->unpaidOrderAt($this->storeA, -40);

        $this->assertEquals(100, $this->actingAs($this->managerA)
            ->getJson('/api/dashboard')->json('stats.today_sales'));
    }
}
