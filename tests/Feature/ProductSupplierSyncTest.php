<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductSupplierSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private Supplier $supplierA;
    private Supplier $supplierB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);

        $this->supplierA = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->supplierB = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function linkedSupplierIds(Product $product): array
    {
        return DB::table('supplier_products')
            ->where('product_id', $product->id)
            ->orderBy('supplier_id')
            ->pluck('supplier_id')
            ->all();
    }

    // ─────────────────────────────────────────
    // CREATE
    // ─────────────────────────────────────────

    public function test_create_product_with_supplier_ids_links_them_and_stamps_tenant(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/products', [
                'name'         => 'Drill',
                'sku'          => 'DRL-1',
                'price'        => 100,
                'supplier_ids' => [$this->supplierA->id, $this->supplierB->id],
            ])->assertStatus(201);

        $product = Product::where('sku', 'DRL-1')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [$this->supplierA->id, $this->supplierB->id],
            $this->linkedSupplierIds($product)
        );

        $this->assertSame(
            0,
            DB::table('supplier_products')->where('tenant_id', '!=', $this->tenant->id)->count()
        );
    }

    public function test_duplicate_supplier_ids_do_not_create_duplicate_rows(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/products', [
                'name'         => 'Hammer',
                'sku'          => 'HMR-1',
                'price'        => 50,
                'supplier_ids' => [$this->supplierA->id, $this->supplierA->id, (string) $this->supplierA->id],
            ])->assertStatus(201);

        $product = Product::where('sku', 'HMR-1')->firstOrFail();

        $this->assertSame([$this->supplierA->id], $this->linkedSupplierIds($product));
    }

    public function test_create_product_without_supplier_ids_links_nothing(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/products', [
                'name'  => 'Nails',
                'sku'   => 'NLS-1',
                'price' => 5,
            ])->assertStatus(201);

        $product = Product::where('sku', 'NLS-1')->firstOrFail();

        $this->assertSame([], $this->linkedSupplierIds($product));
    }

    public function test_create_rejects_a_supplier_from_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherSupplier = Supplier::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($this->admin)
            ->postJson('/api/products', [
                'name'         => 'Saw',
                'sku'          => 'SAW-1',
                'price'        => 80,
                'supplier_ids' => [$otherSupplier->id],
            ])->assertStatus(422)
            ->assertJsonValidationErrors('supplier_ids.0');

        $this->assertDatabaseMissing('products', ['sku' => 'SAW-1']);
    }

    // ─────────────────────────────────────────
    // UPDATE — full-set sync semantics
    // ─────────────────────────────────────────

    public function test_update_adds_and_removes_to_match_the_submitted_set(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $product->syncSuppliers([$this->supplierA->id]);

        $this->actingAs($this->admin)
            ->putJson("/api/products/{$product->id}", [
                'name'         => $product->name,
                'sku'          => (string) $product->sku,
                'price'        => $product->price,
                'supplier_ids' => [$this->supplierB->id],
            ])->assertStatus(200);

        $this->assertSame([$this->supplierB->id], $this->linkedSupplierIds($product));
    }

    public function test_update_omitting_supplier_ids_leaves_membership_untouched(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $product->syncSuppliers([$this->supplierA->id, $this->supplierB->id]);

        $this->actingAs($this->admin)
            ->putJson("/api/products/{$product->id}", [
                'name'  => 'Renamed',
                'sku'   => (string) $product->sku,
                'price' => 999,
            ])->assertStatus(200);

        $this->assertEqualsCanonicalizing(
            [$this->supplierA->id, $this->supplierB->id],
            $this->linkedSupplierIds($product)
        );
    }

    public function test_update_with_empty_array_clears_all_suppliers(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $product->syncSuppliers([$this->supplierA->id, $this->supplierB->id]);

        $this->actingAs($this->admin)
            ->putJson("/api/products/{$product->id}", [
                'name'         => $product->name,
                'sku'          => (string) $product->sku,
                'price'        => $product->price,
                'supplier_ids' => [],
            ])->assertStatus(200);

        $this->assertSame([], $this->linkedSupplierIds($product));
    }

    public function test_update_rejects_a_supplier_from_another_tenant(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $product->syncSuppliers([$this->supplierA->id]);

        $otherTenant = Tenant::factory()->create();
        $otherSupplier = Supplier::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($this->admin)
            ->putJson("/api/products/{$product->id}", [
                'name'         => $product->name,
                'sku'          => (string) $product->sku,
                'price'        => $product->price,
                'supplier_ids' => [$otherSupplier->id],
            ])->assertStatus(422)
            ->assertJsonValidationErrors('supplier_ids.0');

        $this->assertSame([$this->supplierA->id], $this->linkedSupplierIds($product));
    }

    // ─────────────────────────────────────────
    // SUPPLIERS INDEX — per_page=all
    // ─────────────────────────────────────────

    public function test_supplier_index_per_page_all_returns_every_row_with_intact_meta(): void
    {
        Supplier::factory()->count(23)->create(['tenant_id' => $this->tenant->id]);
        $total = Supplier::where('tenant_id', $this->tenant->id)->count();

        $this->actingAs($this->admin)
            ->getJson('/api/suppliers?per_page=all')
            ->assertStatus(200)
            ->assertJsonCount($total, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.total', $total)
            ->assertJsonPath('stats.total_suppliers', $total);
    }

    public function test_supplier_index_default_still_paginates(): void
    {
        Supplier::factory()->count(23)->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->admin)
            ->getJson('/api/suppliers')
            ->assertStatus(200)
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.current_page', 1);
    }
}
