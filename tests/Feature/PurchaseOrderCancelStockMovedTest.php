<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\LedgerEntry;
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
 * Cancelling a purchase order takes its received stock back out of the location it was
 * received into. If any of that stock has moved away, the cancel is refused before anything
 * is written.
 */
class PurchaseOrderCancelStockMovedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Warehouse $shelf;
    private Warehouse $storage;
    private Product $product;
    private Supplier $supplier;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $store          = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);
        $this->shelf    = $store->shelf;
        $this->storage  = Warehouse::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'name' => 'Back room']);
        $this->product  = Product::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'name'              => 'Water',
            'unit'              => 'pcs',
            'secondary_unit'    => 'box',
            'conversion_factor' => 12,
        ]);
        $this->supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin    = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => null, 'role' => 'tenant_admin']);
    }

    private function receive(array $items): int
    {
        return $this->actingAs($this->admin)->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items'       => array_map(fn ($item) => $item + [
                'product_id'   => $this->product->id,
                'warehouse_id' => $this->storage->id,
                'unit_price'   => 60,
            ], $items),
        ])->assertStatus(201)->json('data.id');
    }

    private function cancel(int $poId)
    {
        return $this->actingAs($this->admin)->withHeaders(['X-Locale' => 'en'])->deleteJson("/api/purchase-orders/{$poId}");
    }

    private function qty(Warehouse $warehouse): int
    {
        return (int) Inventory::withoutGlobalScopes()->where('warehouse_id', $warehouse->id)->where('product_id', $this->product->id)->value('quantity');
    }

    private function purchaseOuts(): int
    {
        return InventoryTransaction::withoutGlobalScopes()->where('type', InventoryTransaction::TYPE_PURCHASE_OUT)->count();
    }

    public function test_cancel_is_refused_when_received_stock_has_moved_and_nothing_changes(): void
    {
        $poId = $this->receive([['quantity' => 3, 'unit_type' => 'secondary']]); // 36 pcs into storage

        // Move 1 box to the shelf: only 24 of the 36 received are still in storage.
        $this->actingAs($this->admin)->postJson('/api/stock-transfers', [
            'from_warehouse_id' => $this->storage->id,
            'to_warehouse_id'   => $this->shelf->id,
            'items'             => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_type' => 'secondary']],
        ])->assertStatus(201);

        $ledgerBefore = LedgerEntry::withoutGlobalScopes()->count();

        $this->cancel($poId)
            ->assertStatus(422)
            ->assertJsonValidationErrors('purchase_order')
            ->assertJsonPath('message', 'Cannot cancel: 36 of Water were received into Back room, but only 24 are still there. Move the stock back first');

        $this->assertNotSoftDeleted('purchase_orders', ['id' => $poId]);
        $this->assertEquals(0, $this->purchaseOuts());
        $this->assertEquals($ledgerBefore, LedgerEntry::withoutGlobalScopes()->count());
        $this->assertDatabaseMissing('ledger_entries', [
            'type'           => 'PURCHASE_REVERSAL',
            'reference_type' => PurchaseOrder::class,
            'reference_id'   => $poId,
        ]);
        $this->assertEquals([24, 12], [$this->qty($this->storage), $this->qty($this->shelf)]);
    }

    public function test_lines_received_into_the_same_location_are_checked_together(): void
    {
        // 1 box + 5 pcs of the same product into storage = 17 pcs needed back.
        $poId = $this->receive([
            ['quantity' => 1, 'unit_type' => 'secondary'],
            ['quantity' => 5, 'unit_type' => 'base'],
        ]);

        // Move a single piece away: each line alone (12 or 5) would still fit in the 16 left.
        $this->actingAs($this->admin)->postJson('/api/stock-transfers', [
            'from_warehouse_id' => $this->storage->id,
            'to_warehouse_id'   => $this->shelf->id,
            'items'             => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->cancel($poId)->assertStatus(422)->assertJsonValidationErrors('purchase_order');

        $this->assertNotSoftDeleted('purchase_orders', ['id' => $poId]);
        $this->assertEquals(0, $this->purchaseOuts());
        $this->assertEquals([16, 1], [$this->qty($this->storage), $this->qty($this->shelf)]);
    }

    public function test_untouched_received_stock_can_still_be_cancelled(): void
    {
        $poId = $this->receive([['quantity' => 3, 'unit_type' => 'secondary']]); // 36 pcs

        $this->cancel($poId)->assertStatus(200);

        $this->assertSoftDeleted('purchase_orders', ['id' => $poId]);
        $this->assertEquals(0, $this->qty($this->storage));
        $this->assertDatabaseHas('inventory_transactions', [
            'type'         => InventoryTransaction::TYPE_PURCHASE_OUT,
            'warehouse_id' => $this->storage->id,
            'quantity'     => 36,
            'reference_id' => $poId,
        ]);
        $this->assertEquals(1, PurchaseOrder::withTrashed()->whereKey($poId)->count());
    }
}
