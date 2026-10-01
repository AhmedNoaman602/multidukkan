<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Warehouse $shelf;
    private Warehouse $storage;
    private Product $product;
    private User $admin;
    private User $manager;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant  = Tenant::factory()->create();
        $this->store   = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);
        $this->shelf   = $this->store->shelf;
        $this->storage = Warehouse::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        $this->product = Product::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'unit'              => 'pcs',
            'secondary_unit'    => 'box',
            'conversion_factor' => 12,
        ]);

        $this->stock($this->shelf, 10);
        $this->stock($this->storage, 100);

        $this->admin   = $this->user('tenant_admin', null);
        $this->manager = $this->user('store_manager', $this->store->id);
        $this->staff   = $this->user('store_staff', $this->store->id);
    }

    private function user(string $role, ?int $storeId, ?int $tenantId = null): User
    {
        return User::factory()->create([
            'tenant_id' => $tenantId ?? $this->tenant->id,
            'store_id'  => $storeId,
            'role'      => $role,
        ]);
    }

    private function stock(Warehouse $warehouse, int $quantity): void
    {
        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => $quantity,
        ]);
    }

    private function quantityAt(Warehouse $warehouse): ?int
    {
        return Inventory::withoutGlobalScopes()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
    }

    private function transfer(User $actor, array $overrides = [], array $item = [])
    {
        return $this->actingAs($actor)
            ->withHeaders(['X-Locale' => 'en'])
            ->postJson('/api/stock-transfers', array_merge([
                'from_warehouse_id' => $this->storage->id,
                'to_warehouse_id'   => $this->shelf->id,
                'items'             => [array_merge(['product_id' => $this->product->id, 'quantity' => 2, 'unit_type' => 'secondary'], $item)],
            ], $overrides));
    }

    private function assertNothingMoved(): void
    {
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseCount('stock_transfer_items', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertEquals(10, $this->quantityAt($this->shelf));
        $this->assertEquals(100, $this->quantityAt($this->storage));
    }

    public function test_manager_moves_two_boxes_from_storage_to_shelf(): void
    {
        $id = $this->transfer($this->manager)
            ->assertStatus(201)
            ->assertJsonPath('data.type', StockTransfer::TYPE_MANUAL)
            ->assertJsonPath('data.status', StockTransfer::STATUS_COMPLETED)
            ->assertJsonPath('data.from.id', $this->storage->id)
            ->assertJsonPath('data.to.id', $this->shelf->id)
            ->assertJsonPath('data.to.type', Warehouse::TYPE_SHELF)
            ->assertJsonPath('data.creator.id', $this->manager->id)
            ->assertJsonPath('data.order', null)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_name', 'box')
            ->assertJsonPath('data.items.0.base_quantity', 24)
            ->json('data.id');

        $this->assertEquals(76, $this->quantityAt($this->storage));
        $this->assertEquals(34, $this->quantityAt($this->shelf));
        $this->assertEquals(110, $this->quantityAt($this->storage) + $this->quantityAt($this->shelf));

        $transfer = StockTransfer::withoutGlobalScopes()->findOrFail($id);
        $this->assertDatabaseHas('stock_transfers', [
            'id'       => $id,
            'store_id' => $this->store->id,
            'order_id' => null,
        ]);
        $this->assertNotNull($transfer->completed_at);
        $this->assertDatabaseHas('stock_transfer_items', [
            'stock_transfer_id' => $id,
            'product_id'        => $this->product->id,
            'quantity'          => 2,
            'unit_type'         => 'secondary',
            'conversion_factor' => 12,
            'unit_name'         => 'box',
        ]);

        foreach ([['TRANSFER_OUT', $this->storage], ['TRANSFER_IN', $this->shelf]] as [$type, $warehouse]) {
            $this->assertDatabaseHas('inventory_transactions', [
                'type'           => $type,
                'warehouse_id'   => $warehouse->id,
                'product_id'     => $this->product->id,
                'quantity'       => 24,
                'reference_type' => StockTransfer::class,
                'reference_id'   => $id,
                'batch_id'       => $transfer->batch_id,
                'user_id'        => $this->manager->id,
            ]);
        }
        $this->assertDatabaseCount('inventory_transactions', 2);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_transfer_creates_the_destination_stock_row_when_missing(): void
    {
        $backRoom = Warehouse::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);

        $this->transfer($this->admin, ['from_warehouse_id' => $this->shelf->id, 'to_warehouse_id' => $backRoom->id], ['quantity' => 4, 'unit_type' => 'base'])
            ->assertStatus(201)
            ->assertJsonPath('data.items.0.base_quantity', 4);

        $this->assertEquals(6, $this->quantityAt($this->shelf));
        $this->assertEquals(4, $this->quantityAt($backRoom));
        $this->assertDatabaseHas('inventory', ['warehouse_id' => $backRoom->id, 'product_id' => $this->product->id, 'threshold' => 0]);
    }

    public function test_insufficient_source_stock_moves_nothing(): void
    {
        $this->transfer($this->manager, [], ['quantity' => 9])
            ->assertStatus(422);

        $this->assertNothingMoved();
    }

    public function test_source_and_destination_must_differ(): void
    {
        $this->transfer($this->manager, ['to_warehouse_id' => $this->storage->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_warehouse_id');

        $this->assertNothingMoved();
    }

    public function test_destination_in_another_store_is_rejected(): void
    {
        $otherStore = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);

        $this->transfer($this->admin, ['to_warehouse_id' => $otherStore->shelf->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_warehouse_id');

        $this->assertNothingMoved();
        $this->assertDatabaseMissing('inventory', ['warehouse_id' => $otherStore->shelf->id]);
    }

    public function test_warehouse_from_another_tenant_is_rejected(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreignStore = Store::factory()->create(['tenant_id' => $otherTenant->id]);
        $foreign = Warehouse::factory()->create(['tenant_id' => $otherTenant->id, 'store_id' => $foreignStore->id]);

        $this->transfer($this->admin, ['from_warehouse_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('from_warehouse_id');

        $this->assertNothingMoved();
    }

    public function test_store_staff_cannot_create_a_transfer(): void
    {
        $this->transfer($this->staff)->assertStatus(403);

        $this->assertNothingMoved();
    }

    public function test_manager_of_another_store_cannot_transfer_from_this_store(): void
    {
        $otherStore = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherManager = $this->user('store_manager', $otherStore->id);

        $this->transfer($otherManager)->assertStatus(403);

        $this->assertNothingMoved();
    }

    private function order(): Order
    {
        return Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => Customer::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'total'       => 0,
        ]);
    }

    public function test_client_cannot_create_a_replenishment_or_link_an_order(): void
    {
        $this->transfer($this->admin, [
            'type'     => StockTransfer::TYPE_REPLENISHMENT,
            'status'   => StockTransfer::STATUS_PENDING,
            'order_id' => $this->order()->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'status', 'order_id']);

        $this->assertNothingMoved();
    }

    // Even values that match what the server would set are rejected: the client doesn't send them at all.
    public function test_each_server_controlled_field_is_rejected_on_its_own(): void
    {
        foreach (['type' => StockTransfer::TYPE_MANUAL, 'status' => StockTransfer::STATUS_COMPLETED, 'order_id' => $this->order()->id] as $field => $value) {
            $this->transfer($this->manager, [$field => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors([$field]);
        }

        $this->assertNothingMoved();
    }

    public function test_listing_is_scoped_to_the_users_store(): void
    {
        $otherStore = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);
        $otherStorage = Warehouse::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $otherStore->id]);
        $this->stock($otherStorage, 30);
        $otherManager = $this->user('store_manager', $otherStore->id);

        $ownId = $this->transfer($this->manager)->assertStatus(201)->json('data.id');
        $otherId = $this->transfer($otherManager, [
            'from_warehouse_id' => $otherStorage->id,
            'to_warehouse_id'   => $otherStore->shelf->id,
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($this->manager)->getJson('/api/stock-transfers')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownId);

        $this->actingAs($this->staff)->getJson('/api/stock-transfers')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownId);

        $this->actingAs($this->admin)->getJson('/api/stock-transfers')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->admin)->getJson("/api/stock-transfers?warehouse_id={$otherStorage->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $otherId);

        $this->actingAs($this->manager)->getJson("/api/stock-transfers/{$otherId}")->assertStatus(403);
        $this->actingAs($this->staff)->getJson("/api/stock-transfers/{$ownId}")->assertStatus(200);
    }

    public function test_audit_feed_shows_a_transfer_once(): void
    {
        $this->transfer($this->manager)->assertStatus(201);

        $this->actingAs($this->admin)->getJson('/api/audit-log?source=inventory')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'TRANSFER')
            ->assertJsonPath('data.0.quantity', 24)
            ->assertJsonPath('data.0.auditable_type', 'StockTransfer')
            ->assertJsonPath('data.0.entity_name', "{$this->storage->name} → {$this->shelf->name}");
    }
}
