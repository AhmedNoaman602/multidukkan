<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every store owns exactly one shelf — a warehouses row with type 'shelf' created together
 * with the store. Every other warehouse is storage.
 */
class StoreShelfTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
    }

    private function shelvesOf(int $storeId): int
    {
        return Warehouse::where('store_id', $storeId)->where('type', Warehouse::TYPE_SHELF)->count();
    }

    public function test_creating_a_store_creates_exactly_one_shelf(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Locale' => 'en'])
            ->postJson('/api/stores', ['name' => 'Branch', 'address' => 'Alexandria'])
            ->assertStatus(201);

        $storeId = $response->json('data.id');
        $shelfId = $response->json('data.shelf.id');

        $this->assertNotNull($shelfId);
        $this->assertEquals(1, $this->shelvesOf($storeId));
        $this->assertDatabaseHas('warehouses', [
            'id'        => $shelfId,
            'tenant_id' => $this->tenant->id,
            'store_id'  => $storeId,
            'type'      => Warehouse::TYPE_SHELF,
            'name'      => 'Shelf',
            'address'   => 'Alexandria',
        ]);
        $this->assertDatabaseCount('warehouses', 1);
    }

    public function test_creating_a_warehouse_always_creates_storage(): void
    {
        $store = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);

        $id = $this->actingAs($this->admin)
            ->postJson('/api/warehouses', ['name' => 'Back room', 'store_id' => $store->id, 'type' => 'shelf'])
            ->assertStatus(201)
            ->assertJsonPath('data.type', Warehouse::TYPE_STORAGE)
            ->json('data.id');

        $this->assertDatabaseHas('warehouses', ['id' => $id, 'type' => Warehouse::TYPE_STORAGE]);
        $this->assertEquals(1, $this->shelvesOf($store->id));
    }

    public function test_a_store_cannot_have_a_second_shelf(): void
    {
        $store = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);

        try {
            Warehouse::factory()->shelf()->create(['tenant_id' => $this->tenant->id, 'store_id' => $store->id]);
            $this->fail('A second shelf was accepted.');
        } catch (QueryException) {
            // expected: the unique index on shelf_store_id
        }

        $this->assertEquals(1, $this->shelvesOf($store->id));
    }

    public function test_the_shelf_cannot_be_deleted(): void
    {
        $store = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);
        $shelf = $store->shelf;

        $this->actingAs($this->admin)
            ->withHeaders(['X-Locale' => 'en'])
            ->deleteJson("/api/warehouses/{$shelf->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', "The store's shelf cannot be deleted");

        $this->assertDatabaseHas('warehouses', ['id' => $shelf->id, 'type' => Warehouse::TYPE_SHELF]);
    }

    public function test_updating_a_warehouse_cannot_change_its_type(): void
    {
        $store = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);
        $shelf = $store->shelf;

        $this->actingAs($this->admin)
            ->putJson("/api/warehouses/{$shelf->id}", ['name' => 'Front shelf', 'store_id' => $store->id, 'type' => 'storage'])
            ->assertStatus(200);

        $this->assertDatabaseHas('warehouses', ['id' => $shelf->id, 'name' => 'Front shelf', 'type' => Warehouse::TYPE_SHELF]);
    }

    public function test_store_response_includes_its_shelf(): void
    {
        $store = Store::factory()->withShelf()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->admin)
            ->getJson("/api/stores/{$store->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.shelf.id', $store->shelf->id);
    }

    public function test_an_order_item_cannot_be_stored_without_a_warehouse(): void
    {
        $store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $product  = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $order    = Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $store->id,
            'customer_id' => $customer->id,
            'total'       => 10,
        ]);

        try {
            DB::table('order_items')->insert([
                'order_id'     => $order->id,
                'product_id'   => $product->id,
                'product_name' => $product->name,
                'quantity'     => 1,
                'unit_name'    => 'pcs',
                'unit_price'   => 10,
            ]);
            $this->fail('An order item without a warehouse was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('warehouse_id', $e->getMessage());
        }

        $this->assertDatabaseCount('order_items', 0);
    }
}
