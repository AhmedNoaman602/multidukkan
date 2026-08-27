<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierProductPivotTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Supplier $supplier;
    protected Product $product;
    protected User $admin;
    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin    = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->warehouse = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
    }

    private function linkProduct(array $pivot = []): void
    {
        $this->supplier->products()->attach($this->product->id, $pivot + [
            'tenant_id'    => $this->tenant->id,
            'cost_price'   => 80,
            'is_preferred' => true,
            'notes'        => 'Original note',
        ]);
    }

    private function pivotRow(): object
    {
        return DB::table('supplier_products')
            ->where('supplier_id', $this->supplier->id)
            ->where('product_id', $this->product->id)
            ->first();
    }

    // ─────────────────────────────────────────
    // TENANT OWNERSHIP
    // ─────────────────────────────────────────

    public function test_attach_stamps_the_tenant_of_both_supplier_and_product(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'cost_price' => 75,
            ])->assertStatus(200);

        $pivot = $this->pivotRow();

        $this->assertSame($this->tenant->id, (int) $pivot->tenant_id);
        $this->assertSame($this->supplier->tenant_id, (int) $pivot->tenant_id);
        $this->assertSame($this->product->tenant_id, (int) $pivot->tenant_id);
    }

    public function test_bulk_attach_stamps_the_correct_tenant(): void
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
        $this->assertSame(
            0,
            DB::table('supplier_products')->where('tenant_id', '!=', $this->tenant->id)->count()
        );
    }

    public function test_attach_ignores_a_client_supplied_tenant_id(): void
    {
        $otherTenant = Tenant::factory()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'cost_price' => 75,
                'tenant_id'  => $otherTenant->id,
            ])->assertStatus(200);

        $this->assertSame($this->tenant->id, (int) $this->pivotRow()->tenant_id);
    }

    public function test_bulk_attach_ignores_a_client_supplied_tenant_id(): void
    {
        $otherTenant = Tenant::factory()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/bulk", [
                'products' => [
                    ['product_id' => $this->product->id, 'cost_price' => 60, 'tenant_id' => $otherTenant->id],
                ],
            ])->assertStatus(200);

        $this->assertSame($this->tenant->id, (int) $this->pivotRow()->tenant_id);
    }

    public function test_update_ignores_a_client_supplied_tenant_id(): void
    {
        $this->linkProduct();
        $otherTenant = Tenant::factory()->create();

        $this->actingAs($this->admin)
            ->putJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'notes'     => 'Changed',
                'tenant_id' => $otherTenant->id,
            ])->assertStatus(200);

        $this->assertSame($this->tenant->id, (int) $this->pivotRow()->tenant_id);
    }

    public function test_purchase_order_pivot_sync_stamps_the_correct_tenant(): void
    {
        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => 10,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/purchase-orders', [
                'supplier_id' => $this->supplier->id,
                'items'       => [
                    [
                        'product_id'   => $this->product->id,
                        'quantity'     => 2,
                        'warehouse_id' => $this->warehouse->id,
                        'unit_price'   => 100,
                    ],
                ],
            ])->assertStatus(201);

        $this->assertSame($this->tenant->id, (int) $this->pivotRow()->tenant_id);
    }

    // ─────────────────────────────────────────
    // PARTIAL UPDATES — omitted ≠ null
    // ─────────────────────────────────────────

    public function test_updating_notes_only_preserves_cost_and_preference(): void
    {
        $this->linkProduct();

        $this->actingAs($this->admin)
            ->putJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'notes' => 'Renegotiated',
            ])->assertStatus(200);

        $pivot = $this->pivotRow();

        $this->assertEquals(80, $pivot->cost_price);
        $this->assertEquals(1, $pivot->is_preferred);
        $this->assertSame('Renegotiated', $pivot->notes);
    }

    public function test_updating_cost_only_preserves_notes_and_preference(): void
    {
        $this->linkProduct();

        $this->actingAs($this->admin)
            ->putJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'cost_price' => 55,
            ])->assertStatus(200);

        $pivot = $this->pivotRow();

        $this->assertEquals(55, $pivot->cost_price);
        $this->assertSame('Original note', $pivot->notes);
        $this->assertEquals(1, $pivot->is_preferred);
    }

    public function test_updating_preference_only_preserves_cost_and_notes(): void
    {
        $this->linkProduct(['is_preferred' => false]);

        $this->actingAs($this->admin)
            ->putJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'is_preferred' => true,
            ])->assertStatus(200);

        $pivot = $this->pivotRow();

        $this->assertEquals(1, $pivot->is_preferred);
        $this->assertEquals(80, $pivot->cost_price);
        $this->assertSame('Original note', $pivot->notes);
    }

    public function test_false_is_not_treated_as_omitted(): void
    {
        $this->linkProduct();

        $this->actingAs($this->admin)
            ->putJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'is_preferred' => false,
            ])->assertStatus(200);

        $pivot = $this->pivotRow();

        $this->assertEquals(0, $pivot->is_preferred);
        $this->assertEquals(80, $pivot->cost_price);
        $this->assertSame('Original note', $pivot->notes);
    }

    public function test_zero_cost_is_not_treated_as_omitted(): void
    {
        $this->linkProduct();

        $this->actingAs($this->admin)
            ->putJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'cost_price' => 0,
            ])->assertStatus(200);

        $this->assertEquals(0, $this->pivotRow()->cost_price);
        $this->assertSame('Original note', $this->pivotRow()->notes);
    }

    public function test_explicit_null_clears_a_nullable_field(): void
    {
        $this->linkProduct();

        $this->actingAs($this->admin)
            ->putJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'cost_price' => null,
            ])->assertStatus(200);

        $pivot = $this->pivotRow();

        $this->assertNull($pivot->cost_price);
        $this->assertSame('Original note', $pivot->notes);
    }

    public function test_explicit_null_is_rejected_for_a_non_nullable_field(): void
    {
        $this->linkProduct();

        $this->actingAs($this->admin)
            ->putJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'is_preferred' => null,
            ])->assertStatus(422)
            ->assertJsonValidationErrors('is_preferred');

        $this->assertEquals(1, $this->pivotRow()->is_preferred);
    }

    public function test_empty_update_body_changes_nothing(): void
    {
        $this->linkProduct();

        $this->actingAs($this->admin)
            ->putJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [])
            ->assertStatus(200);

        $pivot = $this->pivotRow();

        $this->assertEquals(80, $pivot->cost_price);
        $this->assertEquals(1, $pivot->is_preferred);
        $this->assertSame('Original note', $pivot->notes);
    }

    // ─────────────────────────────────────────
    // RE-ATTACH SAFETY
    // ─────────────────────────────────────────

    public function test_reattaching_without_pivot_fields_preserves_existing_metadata(): void
    {
        $this->linkProduct();

        $this->actingAs($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}")
            ->assertStatus(200);

        $pivot = $this->pivotRow();

        $this->assertEquals(80, $pivot->cost_price);
        $this->assertEquals(1, $pivot->is_preferred);
        $this->assertSame('Original note', $pivot->notes);
        $this->assertDatabaseCount('supplier_products', 1);
    }

    public function test_bulk_reattaching_without_pivot_fields_preserves_existing_metadata(): void
    {
        $this->linkProduct();

        $this->actingAs($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/bulk", [
                'products' => [
                    ['product_id' => $this->product->id],
                ],
            ])->assertStatus(200);

        $pivot = $this->pivotRow();

        $this->assertEquals(80, $pivot->cost_price);
        $this->assertEquals(1, $pivot->is_preferred);
        $this->assertSame('Original note', $pivot->notes);
    }

    // ─────────────────────────────────────────
    // VALIDATION — the FormRequests are the gatekeepers
    // ─────────────────────────────────────────

    public function test_negative_cost_price_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'cost_price' => -1,
            ])->assertStatus(422)
            ->assertJsonValidationErrors('cost_price');

        $this->assertDatabaseCount('supplier_products', 0);
    }

    public function test_cost_price_above_the_column_maximum_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'cost_price' => 100000000,
            ])->assertStatus(422)
            ->assertJsonValidationErrors('cost_price');

        $this->assertDatabaseCount('supplier_products', 0);
    }

    public function test_notes_longer_than_the_column_is_rejected(): void
    {
        $this->linkProduct();

        $this->actingAs($this->admin)
            ->putJson("/api/suppliers/{$this->supplier->id}/products/{$this->product->id}", [
                'notes' => str_repeat('a', 256),
            ])->assertStatus(422)
            ->assertJsonValidationErrors('notes');

        $this->assertSame('Original note', $this->pivotRow()->notes);
    }

    public function test_bulk_attach_requires_a_products_array(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/bulk", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('products');

        $this->assertDatabaseCount('supplier_products', 0);
    }

    public function test_bulk_attach_rejects_an_unknown_product(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/suppliers/{$this->supplier->id}/products/bulk", [
                'products' => [
                    ['product_id' => 999999],
                ],
            ])->assertStatus(422)
            ->assertJsonValidationErrors('products.0.product_id');

        $this->assertDatabaseCount('supplier_products', 0);
    }
}
