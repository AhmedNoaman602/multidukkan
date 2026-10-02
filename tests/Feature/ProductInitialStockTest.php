<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Initial stock is entered per location, in the unit the user chose; the server converts it
 * to base units. There is no product-level opening quantity.
 */
class ProductInitialStockTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Warehouse $shelf;
    private Warehouse $storage;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant  = Tenant::factory()->create();
        $store         = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);
        $this->shelf   = $store->shelf;
        $this->storage = Warehouse::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $store->id]);
        $this->admin   = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => null, 'role' => 'tenant_admin']);
    }

    private function createProduct(array $stocks, array $overrides = [])
    {
        return $this->actingAs($this->admin)->postJson('/api/products', array_merge([
            'name'              => 'Water',
            'sku'               => 'WTR-1',
            'price'             => 5,
            'unit'              => 'pcs',
            'secondary_unit'    => 'box',
            'conversion_factor' => 12,
            'stocks'            => $stocks,
        ], $overrides));
    }

    private function updateStocks(Product $product, array $stocks)
    {
        return $this->actingAs($this->admin)->putJson("/api/products/{$product->id}", [
            'name'              => $product->name,
            'sku'               => $product->sku,
            'price'             => $product->price,
            'unit'              => $product->unit,
            'secondary_unit'    => $product->secondary_unit,
            'conversion_factor' => $product->conversion_factor,
            'stocks'            => $stocks,
        ]);
    }

    private function qty(Warehouse $warehouse, int $productId): ?int
    {
        return Inventory::withoutGlobalScopes()->where('warehouse_id', $warehouse->id)->where('product_id', $productId)->value('quantity');
    }

    private function adjustments(int $productId)
    {
        return InventoryTransaction::withoutGlobalScopes()
            ->where('product_id', $productId)
            ->orderBy('id')
            ->get(['warehouse_id', 'type', 'quantity'])
            ->map(fn ($t) => [$t->warehouse_id, $t->type, $t->quantity])
            ->all();
    }

    public function test_two_boxes_on_the_shelf_become_twenty_four_base_units(): void
    {
        $id = $this->createProduct([
            ['warehouse_id' => $this->shelf->id, 'quantity' => 2, 'unit_type' => 'secondary'],
        ])->assertStatus(201)
            ->assertJsonPath('data.stocks.0.quantity', 24)
            ->assertJsonPath('data.stocks.0.warehouse_type', Warehouse::TYPE_SHELF)
            ->json('data.id');

        $this->assertEquals(24, $this->qty($this->shelf, $id));
        $this->assertEquals([[$this->shelf->id, 'ADJUSTMENT_IN', 24]], $this->adjustments($id));
    }

    public function test_initial_stock_can_go_to_several_locations_in_one_request(): void
    {
        $id = $this->createProduct([
            ['warehouse_id' => $this->shelf->id, 'quantity' => 2, 'unit_type' => 'secondary'],
            ['warehouse_id' => $this->storage->id, 'quantity' => 5, 'unit_type' => 'secondary'],
        ])->assertStatus(201)->json('data.id');

        $this->assertEquals([24, 60], [$this->qty($this->shelf, $id), $this->qty($this->storage, $id)]);
        $this->assertEquals(
            [[$this->shelf->id, 'ADJUSTMENT_IN', 24], [$this->storage->id, 'ADJUSTMENT_IN', 60]],
            $this->adjustments($id)
        );
    }

    public function test_base_unit_stock_is_stored_as_entered(): void
    {
        $id = $this->createProduct([
            ['warehouse_id' => $this->shelf->id, 'quantity' => 7, 'unit_type' => 'base'],
            ['warehouse_id' => $this->storage->id, 'quantity' => 3], // unit_type defaults to base
        ])->assertStatus(201)->json('data.id');

        $this->assertEquals([7, 3], [$this->qty($this->shelf, $id), $this->qty($this->storage, $id)]);
    }

    public function test_opening_quantity_is_rejected_and_no_product_is_created(): void
    {
        $this->createProduct([], ['opening_quantity' => 30])
            ->assertStatus(422)
            ->assertJsonValidationErrors('opening_quantity');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_the_same_location_cannot_appear_twice(): void
    {
        $this->createProduct([
            ['warehouse_id' => $this->shelf->id, 'quantity' => 1],
            ['warehouse_id' => $this->shelf->id, 'quantity' => 2],
        ])->assertStatus(422)->assertJsonValidationErrors('stocks.0.warehouse_id');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_updating_stock_in_boxes_sets_the_converted_amount_and_logs_the_difference(): void
    {
        $id = $this->createProduct([
            ['warehouse_id' => $this->shelf->id, 'quantity' => 2, 'unit_type' => 'secondary'],
        ])->assertStatus(201)->json('data.id');

        $this->updateStocks(Product::findOrFail($id), [
            ['warehouse_id' => $this->shelf->id, 'quantity' => 1, 'unit_type' => 'secondary'],
        ])->assertStatus(200);

        $this->assertEquals(12, $this->qty($this->shelf, $id));
        $this->assertEquals(
            [[$this->shelf->id, 'ADJUSTMENT_IN', 24], [$this->shelf->id, 'ADJUSTMENT_OUT', 12]],
            $this->adjustments($id)
        );
    }

    public function test_updating_to_an_absolute_base_amount_logs_only_the_difference(): void
    {
        $id = $this->createProduct([
            ['warehouse_id' => $this->shelf->id, 'quantity' => 2, 'unit_type' => 'secondary'],
        ])->assertStatus(201)->json('data.id');

        $this->updateStocks(Product::findOrFail($id), [
            ['warehouse_id' => $this->shelf->id, 'quantity' => 30, 'unit_type' => 'base'],
        ])->assertStatus(200);

        $this->assertEquals(30, $this->qty($this->shelf, $id));
        $this->assertEquals(
            [[$this->shelf->id, 'ADJUSTMENT_IN', 24], [$this->shelf->id, 'ADJUSTMENT_IN', 6]],
            $this->adjustments($id)
        );
    }

    public function test_boxes_and_loose_pieces_become_one_base_amount(): void
    {
        $id = $this->createProduct([
            ['warehouse_id' => $this->shelf->id, 'quantity' => 2, 'unit_type' => 'secondary', 'loose_quantity' => 7],
        ])->assertStatus(201)
            ->assertJsonPath('data.stocks.0.quantity', 31)
            ->json('data.id');

        $this->assertEquals(31, $this->qty($this->shelf, $id));
        $this->assertEquals([[$this->shelf->id, 'ADJUSTMENT_IN', 31]], $this->adjustments($id));
    }

    public function test_editing_to_boxes_and_loose_pieces_logs_the_difference(): void
    {
        $id = $this->createProduct([
            ['warehouse_id' => $this->shelf->id, 'quantity' => 2, 'unit_type' => 'secondary', 'loose_quantity' => 7],
        ])->assertStatus(201)->json('data.id');

        $this->updateStocks(Product::findOrFail($id), [
            ['warehouse_id' => $this->shelf->id, 'quantity' => 1, 'unit_type' => 'secondary', 'loose_quantity' => 3],
        ])->assertStatus(200);

        $this->assertEquals(15, $this->qty($this->shelf, $id));
        $this->assertEquals(
            [[$this->shelf->id, 'ADJUSTMENT_IN', 31], [$this->shelf->id, 'ADJUSTMENT_OUT', 16]],
            $this->adjustments($id)
        );
    }

    public function test_loose_pieces_are_rejected_on_a_base_unit_row(): void
    {
        $this->createProduct([
            ['warehouse_id' => $this->shelf->id, 'quantity' => 31, 'unit_type' => 'base', 'loose_quantity' => 7],
        ])->assertStatus(422)->assertJsonValidationErrors('stocks.0.loose_quantity');

        $this->createProduct([
            ['warehouse_id' => $this->shelf->id, 'quantity' => 31, 'loose_quantity' => 7], // unit_type defaults to base
        ], ['sku' => 'WTR-2'])->assertStatus(422)->assertJsonValidationErrors('stocks.0.loose_quantity');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_loose_pieces_without_a_factor_fall_back_to_raw_quantities(): void
    {
        $id = $this->createProduct(
            [['warehouse_id' => $this->shelf->id, 'quantity' => 2, 'unit_type' => 'secondary', 'loose_quantity' => 7]],
            ['secondary_unit' => null, 'conversion_factor' => null]
        )->assertStatus(201)->json('data.id');

        $this->assertEquals(9, $this->qty($this->shelf, $id));
    }

    public function test_a_secondary_unit_without_a_factor_uses_the_raw_quantity(): void
    {
        $id = $this->createProduct(
            [['warehouse_id' => $this->shelf->id, 'quantity' => 5, 'unit_type' => 'secondary']],
            ['secondary_unit' => null, 'conversion_factor' => null]
        )->assertStatus(201)->json('data.id');

        $this->assertEquals(5, $this->qty($this->shelf, $id));
    }
}
