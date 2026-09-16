<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostDataVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private User $admin;
    private User $manager;
    private User $staff;
    private Product $product;

    private const MARGIN_KEYS = [
        'profit_margin',
        'profit_margin_a',
        'profit_margin_b',
        'profit_margin_c',
        'profit_margin_d',
        'profit_margin_e',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->store  = Store::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);

        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
            'role'      => 'store_manager',
        ]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
            'role'      => 'store_staff',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'price'      => 100,
            'price_a'    => 110,
            'price_b'    => 120,
            'price_c'    => 130,
            'price_d'    => 140,
            'price_e'    => 150,
            'cost_price' => 60,
        ]);
    }

    private function orderWithItem(): Order
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $order = Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $customer->id,
            'total'       => 100,
        ]);

        OrderItem::factory()->create([
            'order_id'   => $order->id,
            'product_id' => $this->product->id,
            'quantity'   => 1,
            'unit_price' => 100,
        ]);

        return $order;
    }

    // ─────────────────────────────────────────
    // ProductResource
    // ─────────────────────────────────────────

    public function test_store_staff_product_list_omits_cost_and_every_margin(): void
    {
        $response = $this->actingAs($this->staff)
            ->getJson('/api/products')
            ->assertStatus(200);

        $row = $response->json('data.0');

        $this->assertArrayNotHasKey('cost_price', $row);

        foreach (self::MARGIN_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }

        // The staff-facing fields must survive the filter.
        $this->assertSame(100, (int) $row['price']);
        $this->assertArrayHasKey('price_a', $row);
        $this->assertArrayHasKey('stocks', $row);
        $this->assertArrayHasKey('unit', $row);
    }

    public function test_tenant_admin_product_list_returns_all_seven_cost_fields(): void
    {
        $row = $this->actingAs($this->admin)
            ->getJson('/api/products')
            ->assertStatus(200)
            ->json('data.0');

        $this->assertArrayHasKey('cost_price', $row);
        $this->assertSame(60, (int) $row['cost_price']);

        foreach (self::MARGIN_KEYS as $key) {
            $this->assertArrayHasKey($key, $row);
        }

        $this->assertSame(40.0, (float) $row['profit_margin']);
        $this->assertSame(90.0, (float) $row['profit_margin_e']);
    }

    public function test_store_manager_product_list_returns_all_seven_cost_fields(): void
    {
        $row = $this->actingAs($this->manager)
            ->getJson('/api/products')
            ->assertStatus(200)
            ->json('data.0');

        $this->assertArrayHasKey('cost_price', $row);

        foreach (self::MARGIN_KEYS as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }

    // ─────────────────────────────────────────
    // OrderResource
    // ─────────────────────────────────────────

    public function test_store_staff_order_items_omit_cost_price(): void
    {
        $order = $this->orderWithItem();

        $item = $this->actingAs($this->staff)
            ->getJson("/api/orders/{$order->id}")
            ->assertStatus(200)
            ->json('items.0');

        $this->assertArrayNotHasKey('cost_price', $item);
        $this->assertArrayHasKey('unit_price', $item);
    }

    public function test_store_manager_order_items_include_cost_price(): void
    {
        $order = $this->orderWithItem();

        $item = $this->actingAs($this->manager)
            ->getJson("/api/orders/{$order->id}")
            ->assertStatus(200)
            ->json('items.0');

        $this->assertArrayHasKey('cost_price', $item);
        $this->assertSame(60, (int) $item['cost_price']);
    }

    // ─────────────────────────────────────────
    // Reports — admin only
    // ─────────────────────────────────────────

    public function test_store_staff_cannot_open_reports(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/reports/daily')
            ->assertStatus(403);
    }

    public function test_store_manager_cannot_open_reports(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/api/reports/daily')
            ->assertStatus(403);
    }

    public function test_tenant_admin_can_open_reports(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/daily')
            ->assertStatus(200);
    }

    // ─────────────────────────────────────────
    // Supplier pricing — whole endpoint is a barrier
    // ─────────────────────────────────────────

    public function test_store_staff_cannot_read_product_suppliers(): void
    {
        $this->actingAs($this->staff)
            ->getJson("/api/products/{$this->product->id}/suppliers")
            ->assertStatus(403);
    }

    public function test_store_manager_can_read_product_suppliers(): void
    {
        $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product->syncSuppliers([$supplier->id]);

        $row = $this->actingAs($this->manager)
            ->getJson("/api/products/{$this->product->id}/suppliers")
            ->assertStatus(200)
            ->json('data.0');

        $this->assertArrayHasKey('cost_price', $row);
        $this->assertArrayHasKey('last_purchase_price', $row);
    }

    public function test_tenant_admin_can_read_product_suppliers(): void
    {
        $this->actingAs($this->admin)
            ->getJson("/api/products/{$this->product->id}/suppliers")
            ->assertStatus(200);
    }

    // ─────────────────────────────────────────
    // Tenant isolation still holds without the policy's tenant comparisons
    // ─────────────────────────────────────────

    public function test_a_foreign_tenants_product_is_unreachable_on_every_product_route(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreign = Product::factory()->create([
            'tenant_id' => $otherTenant->id,
            'name'      => 'Foreign',
            'price'     => 10,
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/products/{$foreign->id}")
            ->assertStatus(404);

        $this->actingAs($this->admin)
            ->putJson("/api/products/{$foreign->id}", [
                'name'  => 'Hijacked',
                'sku'   => (string) $foreign->sku,
                'price' => 999,
            ])->assertStatus(404);

        $this->actingAs($this->admin)
            ->deleteJson("/api/products/{$foreign->id}")
            ->assertStatus(404);

        $this->actingAs($this->admin)
            ->getJson("/api/products/{$foreign->id}/suppliers")
            ->assertStatus(404);

        $this->assertDatabaseHas('products', [
            'id'    => $foreign->id,
            'name'  => 'Foreign',
            'price' => 10,
        ]);
    }

    public function test_a_non_admin_still_cannot_update_or_delete_a_product(): void
    {
        $this->actingAs($this->manager)
            ->putJson("/api/products/{$this->product->id}", [
                'name'  => 'Renamed',
                'sku'   => (string) $this->product->sku,
                'price' => 999,
            ])->assertStatus(403);

        $this->actingAs($this->staff)
            ->deleteJson("/api/products/{$this->product->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('products', [
            'id'    => $this->product->id,
            'name'  => $this->product->name,
            'price' => 100,
        ]);
    }

    // ─────────────────────────────────────────
    // Absent, not null
    // ─────────────────────────────────────────

    public function test_hidden_fields_are_absent_from_the_raw_json_not_null(): void
    {
        $order = $this->orderWithItem();

        $products = $this->actingAs($this->staff)
            ->getJson('/api/products')
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString('cost_price', $products);
        $this->assertStringNotContainsString('profit_margin', $products);

        $orderJson = $this->actingAs($this->staff)
            ->getJson("/api/orders/{$order->id}")
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString('cost_price', $orderJson);
    }
}
