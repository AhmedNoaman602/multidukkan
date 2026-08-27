<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierProductAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private User $admin;
    private User $staff;
    private Supplier $supplier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Tenant']);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Test Store',
            'address'   => 'Test Address',
            'phone'     => '01000000000',
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Admin User',
            'email'     => 'admin@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'tenant_admin',
            'store_id'  => null,
        ]);

        $this->staff = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Staff User',
            'email'     => 'staff@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'store_staff',
            'store_id'  => $this->store->id,
        ]);

        $this->supplier = Supplier::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    private function linkProduct(array $pivot = []): void
    {
        $this->supplier->products()->attach($this->product->id, $pivot + [
            'tenant_id'    => $this->tenant->id,
            'cost_price'   => 80,
            'is_preferred' => false,
            'notes'        => 'Original note',
        ]);
    }

    // ─────────────────────────────────────────
    // ATTACH
    // ─────────────────────────────────────────

    public function test_admin_can_attach_product_to_supplier(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'cost_price'   => 75,
                'is_preferred' => true,
                'notes'        => 'Primary source',
            ])->assertStatus(200);

        $this->assertDatabaseHas('supplier_products', [
            'supplier_id' => $this->supplier->id,
            'product_id'  => $this->product->id,
            'cost_price'  => 75,
        ]);
    }

    public function test_staff_cannot_attach_product_to_supplier(): void
    {
        $this->actingAs($this->staff)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'cost_price' => 75,
            ])->assertStatus(403);

        $this->assertDatabaseMissing('supplier_products', [
            'supplier_id' => $this->supplier->id,
            'product_id'  => $this->product->id,
        ]);
    }

    // ─────────────────────────────────────────
    // BULK ATTACH
    // ─────────────────────────────────────────

    public function test_admin_can_bulk_attach_products_to_supplier(): void
    {
        $second = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/bulk", [
                'products' => [
                    ['product_id' => $this->product->id, 'cost_price' => 60],
                    ['product_id' => $second->id, 'cost_price' => 70],
                ],
            ])->assertStatus(200);

        $this->assertDatabaseCount('supplier_products', 2);
    }

    public function test_staff_cannot_bulk_attach_products_to_supplier(): void
    {
        $second = Product::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->staff)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/bulk", [
                'products' => [
                    ['product_id' => $this->product->id, 'cost_price' => 60],
                    ['product_id' => $second->id, 'cost_price' => 70],
                ],
            ])->assertStatus(403);

        $this->assertDatabaseCount('supplier_products', 0);
    }

    // ─────────────────────────────────────────
    // UPDATE PIVOT
    // ─────────────────────────────────────────

    public function test_admin_can_update_supplier_product_link(): void
    {
        $this->linkProduct();

        $this->actingAs($this->admin)
            ->putJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'cost_price'   => 55,
                'is_preferred' => true,
                'notes'        => 'Renegotiated',
            ])->assertStatus(200);

        $this->assertDatabaseHas('supplier_products', [
            'supplier_id' => $this->supplier->id,
            'product_id'  => $this->product->id,
            'cost_price'  => 55,
            'notes'       => 'Renegotiated',
        ]);
    }

    public function test_staff_cannot_update_supplier_product_link(): void
    {
        $this->linkProduct();

        $this->actingAs($this->staff)
            ->putJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'cost_price'   => 55,
                'is_preferred' => true,
                'notes'        => 'Renegotiated',
            ])->assertStatus(403);

        $this->assertDatabaseHas('supplier_products', [
            'supplier_id'  => $this->supplier->id,
            'product_id'   => $this->product->id,
            'cost_price'   => 80,
            'is_preferred' => false,
            'notes'        => 'Original note',
        ]);
    }

    // ─────────────────────────────────────────
    // DETACH
    // ─────────────────────────────────────────

    public function test_admin_can_detach_product_from_supplier(): void
    {
        $this->linkProduct();

        $this->actingAs($this->admin)
            ->deleteJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('supplier_products', [
            'supplier_id' => $this->supplier->id,
            'product_id'  => $this->product->id,
        ]);
    }

    public function test_staff_cannot_detach_product_from_supplier(): void
    {
        $this->linkProduct();

        $this->actingAs($this->staff)
            ->deleteJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('supplier_products', [
            'supplier_id' => $this->supplier->id,
            'product_id'  => $this->product->id,
        ]);
    }

    // ─────────────────────────────────────────
    // CROSS-TENANT — isolation must survive the new role checks
    // ─────────────────────────────────────────

    public function test_admin_cannot_attach_product_from_another_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other Tenant']);
        $otherProduct = Product::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/{$otherProduct->id}", [
                'cost_price' => 75,
            ])->assertStatus(404);

        $this->assertDatabaseCount('supplier_products', 0);
    }

    public function test_admin_cannot_bulk_attach_product_from_another_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other Tenant']);
        $otherProduct = Product::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/bulk", [
                'products' => [
                    ['product_id' => $otherProduct->id, 'cost_price' => 60],
                ],
            ])->assertStatus(403);

        $this->assertDatabaseCount('supplier_products', 0);
    }
}
