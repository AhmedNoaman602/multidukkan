<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sales are taken from the store's shelf. A short shelf is refilled from the same store's
 * storage (highest stock first, lowest id on ties) inside the sale's transaction (ADR-010).
 */
class ShelfFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Warehouse $shelf;
    private Warehouse $storageA;
    private Warehouse $storageB;
    private Product $product;
    private Customer $customer;
    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);
        $this->shelf    = $this->store->shelf;
        $this->storageA = Warehouse::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        $this->storageB = Warehouse::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        $this->product  = Product::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'price'             => 10,
            'unit'              => 'pcs',
            'secondary_unit'    => 'box',
            'conversion_factor' => 12,
        ]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->stock($this->shelf, 8);
        $this->stock($this->storageA, 30);
        $this->stock($this->storageB, 30);

        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => null, 'role' => 'tenant_admin']);
        $this->staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'role' => 'store_staff']);
    }

    private function stock(Warehouse $warehouse, int $quantity, ?Product $product = null): void
    {
        Inventory::withoutGlobalScopes()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => ($product ?? $this->product)->id],
            ['tenant_id' => $warehouse->tenant_id, 'quantity' => $quantity, 'threshold' => 0]
        );
    }

    private function qty(Warehouse $warehouse, ?Product $product = null): int
    {
        return (int) Inventory::withoutGlobalScopes()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', ($product ?? $this->product)->id)
            ->value('quantity');
    }

    private function sell(User $actor, array $items, array $extra = [])
    {
        return $this->actingAs($actor)
            ->withHeaders(['X-Locale' => 'en'])
            ->postJson('/api/orders', array_merge([
                'store_id'    => $this->store->id,
                'customer_id' => $this->customer->id,
                'order_date'  => now()->toDateString(),
                'items'       => $items,
            ], $extra));
    }

    private function pcs(int $quantity): array
    {
        return ['product_id' => $this->product->id, 'quantity' => $quantity];
    }

    private function boxes(int $quantity): array
    {
        return ['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_type' => 'secondary'];
    }

    private function replenishments()
    {
        return StockTransfer::withoutGlobalScopes()->with('items')->where('type', StockTransfer::TYPE_REPLENISHMENT)->orderBy('id')->get();
    }

    // 1
    public function test_a_sale_the_shelf_can_cover_moves_nothing_from_storage(): void
    {
        $orderId = $this->sell($this->admin, [$this->pcs(5)])->assertStatus(201)->json('id');

        $this->assertEquals([3, 30, 30], [$this->qty($this->shelf), $this->qty($this->storageA), $this->qty($this->storageB)]);
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseHas('inventory_transactions', [
            'type' => 'SALE', 'warehouse_id' => $this->shelf->id, 'quantity' => 5, 'reference_id' => $orderId,
        ]);
        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'warehouse_id' => $this->shelf->id]);
    }

    // 2
    public function test_a_short_shelf_is_refilled_with_the_exact_shortfall_from_the_fullest_storage(): void
    {
        $this->stock($this->storageB, 50); // B has the most stock, despite the higher id

        $orderId = $this->sell($this->admin, [$this->pcs(20)])->assertStatus(201)->json('id');

        $this->assertEquals([0, 30, 38], [$this->qty($this->shelf), $this->qty($this->storageA), $this->qty($this->storageB)]);

        $transfers = $this->replenishments();
        $this->assertCount(1, $transfers);
        $transfer = $transfers->first();
        $this->assertEquals(
            [$this->storageB->id, $this->shelf->id, StockTransfer::STATUS_COMPLETED, $orderId, $this->admin->id, $this->store->id],
            [$transfer->from_warehouse_id, $transfer->to_warehouse_id, $transfer->status, $transfer->order_id, $transfer->created_by, $transfer->store_id]
        );
        $this->assertEquals(
            [12, 'base', 1, 'pcs'],
            [$transfer->items[0]->quantity, $transfer->items[0]->unit_type, $transfer->items[0]->conversion_factor, $transfer->items[0]->unit_name]
        );

        $this->assertDatabaseHas('inventory_transactions', ['type' => 'TRANSFER_OUT', 'warehouse_id' => $this->storageB->id, 'quantity' => 12, 'batch_id' => $transfer->batch_id]);
        $this->assertDatabaseHas('inventory_transactions', ['type' => 'TRANSFER_IN', 'warehouse_id' => $this->shelf->id, 'quantity' => 12, 'batch_id' => $transfer->batch_id]);
        $this->assertDatabaseHas('inventory_transactions', ['type' => 'SALE', 'warehouse_id' => $this->shelf->id, 'quantity' => 20, 'reference_id' => $orderId]);
        $this->assertDatabaseCount('inventory_transactions', 3);
    }

    // 3
    public function test_a_tie_between_storage_locations_uses_the_lower_id(): void
    {
        $this->sell($this->admin, [$this->pcs(20)])->assertStatus(201);

        $this->assertEquals([0, 18, 30], [$this->qty($this->shelf), $this->qty($this->storageA), $this->qty($this->storageB)]);
        $this->assertEquals($this->storageA->id, $this->replenishments()->sole()->from_warehouse_id);
    }

    // 4
    public function test_several_storage_locations_are_used_greedily_with_one_transfer_each(): void
    {
        $this->stock($this->storageA, 10);
        $this->stock($this->storageB, 6);

        $this->sell($this->admin, [$this->pcs(20)])->assertStatus(201);

        $this->assertEquals([0, 0, 4], [$this->qty($this->shelf), $this->qty($this->storageA), $this->qty($this->storageB)]);

        $transfers = $this->replenishments();
        $this->assertCount(2, $transfers);
        $this->assertEquals(
            [[$this->storageA->id, 10], [$this->storageB->id, 2]],
            $transfers->map(fn ($t) => [$t->from_warehouse_id, $t->items->sole()->quantity])->all()
        );
    }

    // 5
    public function test_a_store_that_cannot_cover_the_sale_changes_nothing(): void
    {
        $this->sell($this->admin, [$this->pcs(100)])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'shelf: 8') && str_contains($m, 'storage: 60'));

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseCount('stock_transfer_items', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertEquals([8, 30, 30], [$this->qty($this->shelf), $this->qty($this->storageA), $this->qty($this->storageB)]);
    }

    // 6
    public function test_two_boxes_need_twenty_four_base_units(): void
    {
        $orderId = $this->sell($this->admin, [$this->boxes(2)])->assertStatus(201)->json('id');

        $this->assertEquals([0, 14], [$this->qty($this->shelf), $this->qty($this->storageA)]);
        $this->assertEquals(16, $this->replenishments()->sole()->items->sole()->quantity);
        $this->assertDatabaseHas('inventory_transactions', ['type' => 'SALE', 'quantity' => 24, 'reference_id' => $orderId]);
        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'quantity' => 2, 'unit_type' => 'secondary', 'conversion_factor' => 12]);
    }

    // 7
    public function test_a_box_and_loose_pieces_of_one_product_are_one_need(): void
    {
        $orderId = $this->sell($this->admin, [$this->boxes(1), $this->pcs(5)])->assertStatus(201)->json('id');

        $this->assertEquals([0, 21], [$this->qty($this->shelf), $this->qty($this->storageA)]);
        $this->assertEquals(9, $this->replenishments()->sole()->items->sole()->quantity);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseHas('inventory_transactions', ['type' => 'SALE', 'quantity' => 17, 'reference_id' => $orderId]);
        $this->assertEquals(1, \App\Models\InventoryTransaction::withoutGlobalScopes()->where('type', 'SALE')->count());
    }

    // 8
    public function test_quick_sale_refills_the_shelf_and_records_the_payment(): void
    {
        $orderId = $this->sell($this->admin, [$this->pcs(20)], ['pay_immediately' => true, 'payment_method' => 'cash'])
            ->assertStatus(201)->json('id');

        $this->assertEquals([0, 18], [$this->qty($this->shelf), $this->qty($this->storageA)]);
        $this->assertEquals($orderId, $this->replenishments()->sole()->order_id);
        $this->assertDatabaseHas('payments', ['order_id' => $orderId, 'amount' => 200, 'method' => 'cash']);
    }

    // 9
    public function test_a_staff_sale_that_needs_replenishment_needs_no_approval(): void
    {
        $this->sell($this->staff, [$this->pcs(20)])->assertStatus(201);

        $transfer = $this->replenishments()->sole();
        $this->assertEquals([StockTransfer::STATUS_COMPLETED, $this->staff->id], [$transfer->status, $transfer->created_by]);
        $this->assertEquals([0, 18], [$this->qty($this->shelf), $this->qty($this->storageA)]);
    }

    // 10
    public function test_another_stores_storage_is_never_used(): void
    {
        $otherStore = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);
        $otherStorage = Warehouse::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $otherStore->id]);
        $this->stock($otherStorage, 100);
        $this->stock($this->storageA, 0);
        $this->stock($this->storageB, 0);

        $this->sell($this->admin, [$this->pcs(20)])->assertStatus(422);

        $this->assertEquals([8, 100], [$this->qty($this->shelf), $this->qty($otherStorage)]);
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    // 11
    public function test_adding_an_item_refills_a_short_shelf(): void
    {
        $orderId = $this->sell($this->admin, [$this->pcs(8)])->assertStatus(201)->json('id');

        $this->actingAs($this->admin)->postJson("/api/orders/{$orderId}/items", $this->pcs(5))->assertStatus(200);

        $this->assertEquals([0, 25], [$this->qty($this->shelf), $this->qty($this->storageA)]);
        $transfer = $this->replenishments()->sole();
        $this->assertEquals([$orderId, 5], [$transfer->order_id, $transfer->items->sole()->quantity]);
        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'quantity' => 13, 'warehouse_id' => $this->shelf->id]);
    }

    // 12
    public function test_increasing_a_line_by_a_box_refills_a_short_shelf(): void
    {
        $order = $this->sell($this->admin, [$this->boxes(1)])->assertStatus(201)->json();
        // 12 needed, shelf 8 → 4 from A (A 26, B 30)
        $this->assertEquals([0, 26, 30], [$this->qty($this->shelf), $this->qty($this->storageA), $this->qty($this->storageB)]);

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$order['id']}/items/{$order['items'][0]['id']}", ['quantity' => 2])
            ->assertStatus(200);

        // +12 needed, shelf 0 → B is now the fullest
        $this->assertEquals([0, 26, 18], [$this->qty($this->shelf), $this->qty($this->storageA), $this->qty($this->storageB)]);
        $last = $this->replenishments()->last();
        $this->assertEquals([$this->storageB->id, 12, $order['id']], [$last->from_warehouse_id, $last->items->sole()->quantity, $last->order_id]);
    }

    // 13
    public function test_order_payloads_cannot_choose_a_warehouse(): void
    {
        $this->sell($this->admin, [$this->pcs(1) + ['warehouse_id' => $this->storageA->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.warehouse_id');
        $this->assertDatabaseCount('orders', 0);

        $orderId = $this->sell($this->admin, [$this->pcs(1)])->assertStatus(201)->json('id');

        $this->actingAs($this->admin)
            ->postJson("/api/orders/{$orderId}/items", $this->pcs(1) + ['warehouse_id' => $this->storageA->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('warehouse_id');
        $this->assertDatabaseCount('order_items', 1);
        $this->assertEquals(7, $this->qty($this->shelf));
    }

    // 14
    public function test_availability_reports_shelf_and_storage_per_store(): void
    {
        $unstocked = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherStore = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);
        $otherStorage = Warehouse::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $otherStore->id]);
        $this->stock($otherStorage, 100);

        $url = fn (int $storeId) => '/api/inventory/availability?' . http_build_query([
            'store_id'    => $storeId,
            'product_ids' => [$this->product->id, $unstocked->id],
        ]);

        $this->actingAs($this->admin)->getJson($url($this->store->id))
            ->assertStatus(200)
            ->assertExactJson(['data' => [
                ['product_id' => $this->product->id, 'shelf_quantity' => 8, 'storage_quantity' => 60, 'total_quantity' => 68],
                ['product_id' => $unstocked->id, 'shelf_quantity' => 0, 'storage_quantity' => 0, 'total_quantity' => 0],
            ]]);

        // Staff always get their own store, whatever store_id they send.
        $this->actingAs($this->staff)->getJson($url($otherStore->id))
            ->assertStatus(200)
            ->assertJsonPath('data.0.storage_quantity', 60);

        $foreignStore = Store::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $this->actingAs($this->admin)->getJson($url($foreignStore->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors('store_id');
    }
}
