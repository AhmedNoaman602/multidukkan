<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Order;
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
 * Line items record the unit and conversion factor used at transaction time. Every later
 * edit and reversal reads that snapshot, never the product's current configuration.
 */
class UnitSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Customer $customer;
    private Product $product;
    private User $admin;
    private Warehouse $warehouse;
    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'price'             => 5,
            'unit'              => 'pcs',
            'secondary_unit'    => 'box',
            'conversion_factor' => 12,
        ]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->warehouse = Warehouse::factory()->shelf()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        $this->inventory = Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => 100,
        ]);
    }

    private function createOrder(array $items): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => array_map(fn ($item) => $item + [
                'product_id'   => $this->product->id,
            ], $items),
        ]);
    }

    private function sellBoxes(int $quantity): Order
    {
        $id = $this->createOrder([['quantity' => $quantity, 'unit_type' => 'secondary']])
            ->assertStatus(201)
            ->json('id');

        return Order::findOrFail($id);
    }

    private function stock(): int
    {
        return $this->inventory->fresh()->quantity;
    }

    private function latestMovement(string $referenceType, int $referenceId): InventoryTransaction
    {
        return InventoryTransaction::where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    public function test_line_items_snapshot_the_selected_unit_and_its_factor(): void
    {
        $id = $this->createOrder([
            ['quantity' => 2, 'unit_type' => 'secondary'],
            ['quantity' => 3, 'unit_type' => 'base'],
        ])->assertStatus(201)->json('id');

        $this->assertDatabaseHas('order_items', [
            'order_id' => $id, 'quantity' => 2, 'unit_type' => 'secondary', 'conversion_factor' => 12, 'unit_name' => 'box',
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $id, 'quantity' => 3, 'unit_type' => 'base', 'conversion_factor' => 1, 'unit_name' => 'pcs',
        ]);
        $this->assertEquals(73, $this->stock());
    }

    public function test_cancel_restores_the_saved_base_quantity_after_the_factor_changes(): void
    {
        $order = $this->sellBoxes(2);
        $this->assertEquals(76, $this->stock());

        $this->product->update(['conversion_factor' => 10]);

        $this->actingAs($this->admin)->deleteJson("/api/orders/{$order->id}")->assertStatus(200);

        $this->assertEquals(100, $this->stock());
        $movement = $this->latestMovement(Order::class, $order->id);
        $this->assertEquals(InventoryTransaction::TYPE_RETURN, $movement->type);
        $this->assertEquals(24, $movement->quantity);
    }

    public function test_adjusting_a_line_uses_the_saved_factor_after_the_factor_changes(): void
    {
        $order = $this->sellBoxes(2);
        $item  = $order->items()->firstOrFail();

        $this->product->update(['conversion_factor' => 10]);

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$order->id}/items/{$item->id}", ['quantity' => 3])
            ->assertStatus(200);

        $this->assertEquals(64, $this->stock());
        $this->assertEquals(12, $this->latestMovement(Order::class, $order->id)->quantity);

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$order->id}/items/{$item->id}", ['quantity' => 1])
            ->assertStatus(200);

        $this->assertEquals(88, $this->stock());
        $movement = $this->latestMovement(Order::class, $order->id);
        $this->assertEquals(InventoryTransaction::TYPE_RETURN, $movement->type);
        $this->assertEquals(24, $movement->quantity);
    }

    public function test_adding_an_item_after_the_factor_changes_does_not_merge_into_the_old_line(): void
    {
        $order = $this->sellBoxes(2);

        $this->product->update(['conversion_factor' => 10]);

        $this->actingAs($this->admin)
            ->postJson("/api/orders/{$order->id}/items", [
                'product_id'   => $this->product->id,
                'quantity'     => 1,
                'unit_type'    => 'secondary',
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 2, 'conversion_factor' => 12]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 1, 'conversion_factor' => 10]);
        $this->assertEquals(2, $order->items()->count());
        $this->assertEquals(66, $this->stock());
    }

    public function test_secondary_unit_without_a_factor_snapshots_a_factor_of_one(): void
    {
        $this->product->update(['conversion_factor' => null]);

        $id = $this->createOrder([['quantity' => 3, 'unit_type' => 'secondary']])
            ->assertStatus(201)
            ->json('id');

        $this->assertDatabaseHas('order_items', [
            'order_id' => $id, 'quantity' => 3, 'unit_type' => 'secondary', 'conversion_factor' => 1, 'unit_name' => 'pcs',
        ]);
        $this->assertEquals(97, $this->stock());
    }

    public function test_purchase_cancel_deducts_the_saved_base_quantity_after_the_factor_changes(): void
    {
        $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);

        $poId = $this->actingAs($this->admin)->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items'       => [[
                'product_id'   => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity'     => 3,
                'unit_type'    => 'secondary',
                'unit_price'   => 60,
            ]],
        ])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $poId, 'quantity' => 3, 'conversion_factor' => 12, 'unit_name' => 'box',
        ]);
        $this->assertEquals(136, $this->stock());

        $this->product->update(['conversion_factor' => 10]);

        $this->actingAs($this->admin)->deleteJson("/api/purchase-orders/{$poId}")->assertStatus(200);

        $this->assertEquals(100, $this->stock());
        $movement = $this->latestMovement(PurchaseOrder::class, $poId);
        $this->assertEquals(InventoryTransaction::TYPE_PURCHASE_OUT, $movement->type);
        $this->assertEquals(36, $movement->quantity);
    }

    public function test_order_shows_the_saved_unit_after_the_product_unit_is_renamed(): void
    {
        $order = $this->sellBoxes(2);

        $this->product->update(['secondary_unit' => 'carton', 'conversion_factor' => 10]);

        $this->actingAs($this->admin)
            ->getJson("/api/orders/{$order->id}")
            ->assertStatus(200)
            ->assertJsonPath('items.0.unit_label', 'box')
            ->assertJsonPath('items.0.conversion_factor', 12)
            ->assertJsonPath('items.0.base_quantity', 24)
            ->assertJsonPath('items_count', 24);
    }

    public function test_insufficient_stock_writes_no_order_or_line_items(): void
    {
        $this->createOrder([['quantity' => 9, 'unit_type' => 'secondary']])->assertStatus(422);

        $this->assertEquals(0, Order::count());
        $this->assertDatabaseCount('order_items', 0);
        $this->assertEquals(100, $this->stock());
        $this->assertDatabaseCount('inventory_transactions', 0);
    }
}
