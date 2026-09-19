<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lists must not offer what the policies refuse. A search hit or a balance-page
 * row the user cannot open is a dead link; a supplier or purchase order in those
 * results is a leak around the view-cost-data gate.
 */
class CrossStoreExposureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $storeA;
    private Store $storeB;
    private User $admin;
    private User $staffA;
    private Customer $customer;
    private Product $product;
    private Warehouse $warehouseA;
    private Warehouse $warehouseB;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->storeA = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->storeB = Store::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);

        $this->staffA = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->storeA->id,
            'role'      => 'store_staff',
        ]);

        $this->customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Zahra Textiles',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price'     => 100,
        ]);

        $this->warehouseA = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->storeA->id,
        ]);

        $this->warehouseB = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->storeB->id,
        ]);

        foreach ([$this->warehouseA, $this->warehouseB] as $warehouse) {
            Inventory::factory()->create([
                'tenant_id'    => $this->tenant->id,
                'warehouse_id' => $warehouse->id,
                'product_id'   => $this->product->id,
                'quantity'     => 100,
            ]);
        }

        $this->supplier = Supplier::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Zahra Supplies',
        ]);

        PurchaseOrder::factory()->create([
            'tenant_id'              => $this->tenant->id,
            'supplier_id'            => $this->supplier->id,
            'supplier_name_snapshot' => 'Zahra Supplies',
            'total'                  => 500,
        ]);
    }

    private function orderIn(Store $store, Warehouse $warehouse): int
    {
        return $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [[
                'product_id'   => $this->product->id,
                'quantity'     => 1,
                'warehouse_id' => $warehouse->id,
            ]],
        ])->assertStatus(201)->json('id');
    }

    // ─────────────────────────────────────────
    // Global search
    // ─────────────────────────────────────────

    public function test_search_returns_only_the_users_own_store_orders(): void
    {
        $ownOrder   = $this->orderIn($this->storeA, $this->warehouseA);
        $otherOrder = $this->orderIn($this->storeB, $this->warehouseB);

        $ids = collect($this->actingAs($this->staffA)
            ->getJson('/api/search?q=Zahra')
            ->assertStatus(200)
            ->json('orders'))->pluck('id')->all();

        $this->assertContains($ownOrder, $ids);
        $this->assertNotContains($otherOrder, $ids);
    }

    public function test_search_returns_every_store_order_for_an_admin(): void
    {
        $ownOrder   = $this->orderIn($this->storeA, $this->warehouseA);
        $otherOrder = $this->orderIn($this->storeB, $this->warehouseB);

        $ids = collect($this->actingAs($this->admin)
            ->getJson('/api/search?q=Zahra')
            ->assertStatus(200)
            ->json('orders'))->pluck('id')->all();

        $this->assertContains($ownOrder, $ids);
        $this->assertContains($otherOrder, $ids);
    }

    public function test_search_hides_suppliers_and_purchase_orders_from_non_admins(): void
    {
        $body = $this->actingAs($this->staffA)
            ->getJson('/api/search?q=Zahra')
            ->assertStatus(200)
            ->json();

        $this->assertSame([], $body['suppliers']);
        $this->assertSame([], $body['purchase_orders']);
    }

    public function test_search_shows_suppliers_and_purchase_orders_to_an_admin(): void
    {
        $body = $this->actingAs($this->admin)
            ->getJson('/api/search?q=Zahra')
            ->assertStatus(200)
            ->json();

        $this->assertNotEmpty($body['suppliers']);
        $this->assertNotEmpty($body['purchase_orders']);
    }

    // ─────────────────────────────────────────
    // Customer balance page
    // ─────────────────────────────────────────

    public function test_customer_summary_flags_which_orders_the_user_may_open(): void
    {
        $ownOrder   = $this->orderIn($this->storeA, $this->warehouseA);
        $otherOrder = $this->orderIn($this->storeB, $this->warehouseB);

        $rows = collect($this->actingAs($this->staffA)
            ->getJson("/api/customers/{$this->customer->id}/summary")
            ->assertStatus(200)
            ->json('orders'))->keyBy('id');

        $this->assertTrue($rows[$ownOrder]['can_view']);
        $this->assertFalse($rows[$otherOrder]['can_view']);
    }

    public function test_customer_summary_keeps_every_order_so_the_balance_stays_explainable(): void
    {
        $this->orderIn($this->storeA, $this->warehouseA);
        $this->orderIn($this->storeB, $this->warehouseB);

        $body = $this->actingAs($this->staffA)
            ->getJson("/api/customers/{$this->customer->id}/summary")
            ->assertStatus(200)
            ->json();

        $this->assertCount(2, $body['orders']);
        $this->assertSame(2, $body['stats']['total_orders']);
        $this->assertSame(200.0, (float) $body['stats']['total_ordered']);
    }

    public function test_customer_summary_marks_all_orders_viewable_for_an_admin(): void
    {
        $this->orderIn($this->storeA, $this->warehouseA);
        $this->orderIn($this->storeB, $this->warehouseB);

        $rows = $this->actingAs($this->admin)
            ->getJson("/api/customers/{$this->customer->id}/summary")
            ->assertStatus(200)
            ->json('orders');

        foreach ($rows as $row) {
            $this->assertTrue($row['can_view']);
        }
    }

    // ─────────────────────────────────────────
    // The dead link itself
    // ─────────────────────────────────────────

    public function test_an_order_hidden_from_search_is_also_refused_by_the_endpoint(): void
    {
        $otherOrder = $this->orderIn($this->storeB, $this->warehouseB);

        $this->actingAs($this->staffA)
            ->getJson("/api/orders/{$otherOrder}")
            ->assertStatus(403);
    }
}
