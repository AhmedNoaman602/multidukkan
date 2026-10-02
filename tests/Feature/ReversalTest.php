<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reversals undo a sale at the line's recorded location, by its sale-time base quantity.
 * Replenishment that refilled the shelf for the sale is a real past movement and stays.
 */
class ReversalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Warehouse $shelf;
    private Warehouse $storage;
    private Product $product;
    private Customer $customer;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);
        $this->shelf    = $this->store->shelf;
        $this->storage  = Warehouse::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        $this->product  = Product::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'price'             => 10,
            'unit'              => 'pcs',
            'secondary_unit'    => 'box',
            'conversion_factor' => 12,
        ]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin    = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => null, 'role' => 'tenant_admin']);

        $this->stock($this->shelf, 8);
        $this->stock($this->storage, 30);
    }

    private function stock(Warehouse $warehouse, int $quantity): void
    {
        Inventory::withoutGlobalScopes()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $this->product->id],
            ['tenant_id' => $this->tenant->id, 'quantity' => $quantity, 'threshold' => 0]
        );
    }

    private function qty(Warehouse $warehouse): int
    {
        return (int) Inventory::withoutGlobalScopes()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
    }

    private function sell(array $item): array
    {
        return $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [['product_id' => $this->product->id] + $item],
        ])->assertStatus(201)->json();
    }

    private function replenishments()
    {
        return StockTransfer::withoutGlobalScopes()->with('items')->where('type', StockTransfer::TYPE_REPLENISHMENT)->get();
    }

    private function transferRows(): int
    {
        return InventoryTransaction::withoutGlobalScopes()
            ->whereIn('type', [InventoryTransaction::TYPE_TRANSFER_OUT, InventoryTransaction::TYPE_TRANSFER_IN])
            ->count();
    }

    public function test_cancelling_a_replenished_sale_returns_everything_to_the_shelf_and_keeps_the_refill(): void
    {
        $storeTotalBefore = $this->qty($this->shelf) + $this->qty($this->storage); // 38

        // Shelf 8 is short of 20: 12 refilled from storage, then 20 sold from the shelf.
        $order = $this->sell(['quantity' => 20]);
        $this->assertEquals([0, 18], [$this->qty($this->shelf), $this->qty($this->storage)]);

        $this->actingAs($this->admin)->deleteJson("/api/orders/{$order['id']}")->assertStatus(200);

        $this->assertEquals(20, $this->qty($this->shelf));   // full sold base quantity back on the shelf
        $this->assertEquals(18, $this->qty($this->storage)); // storage keeps its post-refill amount
        $this->assertEquals($storeTotalBefore, $this->qty($this->shelf) + $this->qty($this->storage));

        $transfer = $this->replenishments()->sole();
        $this->assertEquals(
            [$order['id'], StockTransfer::STATUS_COMPLETED, 12],
            [$transfer->order_id, $transfer->status, $transfer->items->sole()->quantity]
        );
        $this->assertEquals(2, $this->transferRows()); // the OUT + IN pair only; nothing reversed

        $this->assertDatabaseHas('inventory_transactions', [
            'type'         => InventoryTransaction::TYPE_RETURN,
            'warehouse_id' => $this->shelf->id,
            'quantity'     => 20,
            'reference_id' => $order['id'],
        ]);
    }

    public function test_decreasing_a_replenished_line_returns_only_the_difference_to_the_shelf(): void
    {
        $order = $this->sell(['quantity' => 20]);
        $this->assertEquals([0, 18], [$this->qty($this->shelf), $this->qty($this->storage)]);

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$order['id']}/items/{$order['items'][0]['id']}", ['quantity' => 15])
            ->assertStatus(200);

        $this->assertEquals(5, $this->qty($this->shelf));    // only the 5 removed from the line
        $this->assertEquals(18, $this->qty($this->storage)); // refill untouched

        $transfer = $this->replenishments()->sole();
        $this->assertEquals(12, $transfer->items->sole()->quantity);
        $this->assertEquals(2, $this->transferRows());
        $this->assertDatabaseHas('inventory_transactions', [
            'type'         => InventoryTransaction::TYPE_RETURN,
            'warehouse_id' => $this->shelf->id,
            'quantity'     => 5,
            'reference_id' => $order['id'],
        ]);
    }

    public function test_a_cancel_uses_the_saved_factor_after_the_product_factor_changes(): void
    {
        $this->stock($this->shelf, 50); // enough on the shelf: no refill in this case

        $order = $this->sell(['quantity' => 2, 'unit_type' => 'secondary']); // 2 box at factor 12 = 24
        $this->assertEquals(26, $this->qty($this->shelf));

        $this->product->update(['conversion_factor' => 10]);

        $this->actingAs($this->admin)->deleteJson("/api/orders/{$order['id']}")->assertStatus(200);

        $this->assertEquals(50, $this->qty($this->shelf)); // 24 returned, not 2 × 10 = 20
        $this->assertDatabaseHas('inventory_transactions', [
            'type'         => InventoryTransaction::TYPE_RETURN,
            'quantity'     => 24,
            'reference_id' => $order['id'],
        ]);

        $newOrder = $this->sell(['quantity' => 1, 'unit_type' => 'secondary']);

        $this->assertEquals(40, $this->qty($this->shelf)); // a new box is 10 now
        $this->assertDatabaseHas('order_items', ['order_id' => $newOrder['id'], 'quantity' => 1, 'conversion_factor' => 10]);
        $this->assertDatabaseHas('inventory_transactions', [
            'type'         => InventoryTransaction::TYPE_SALE,
            'quantity'     => 10,
            'reference_id' => $newOrder['id'],
        ]);
    }
}
