<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MUL2-26: lines of the same product merge only when their effective unit price is the
 * same. A different price is a separate line, never silently replaced by the other.
 */
class OrderLineMergeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Customer $customer;
    private Product $product;
    private User $admin;
    private Warehouse $shelf;
    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'price_tier' => 'default']);
        $this->product  = Product::factory()->create([
            'tenant_id'         => $this->tenant->id,
            'price'             => 10,
            'unit'              => 'pcs',
            'secondary_unit'    => 'box',
            'conversion_factor' => 12,
        ]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->shelf = Warehouse::factory()->shelf()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        $this->inventory = Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->shelf->id,
            'product_id'   => $this->product->id,
            'quantity'     => 100,
        ]);
    }

    private function createOrder(array $items): Order
    {
        $id = $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => array_map(fn ($item) => $item + ['product_id' => $this->product->id], $items),
        ])->assertStatus(201)->json('id');

        return Order::findOrFail($id);
    }

    private function addItem(Order $order, array $data): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/orders/{$order->id}/items", $data + ['product_id' => $this->product->id])
            ->assertSuccessful();
    }

    private function balance(): float
    {
        return app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id);
    }

    private function stock(): int
    {
        return $this->inventory->fresh()->quantity;
    }

    public function test_create_merges_lines_with_the_same_price(): void
    {
        $order = $this->createOrder([
            ['quantity' => 5, 'unit_price' => 10],
            ['quantity' => 3, 'unit_price' => 10],
        ]);

        $this->assertEquals(1, $order->items()->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 8, 'unit_price' => 10]);
        $this->assertEquals(80, (float) $order->total);
        $this->assertEquals(80, $this->balance());
        $this->assertEquals(92, $this->stock());
    }

    public function test_create_keeps_lines_with_different_prices_separate(): void
    {
        $order = $this->createOrder([
            ['quantity' => 5, 'unit_price' => 10],
            ['quantity' => 3, 'unit_price' => 8],
        ]);

        $this->assertEquals(2, $order->items()->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 5, 'unit_price' => 10]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 3, 'unit_price' => 8]);
        $this->assertEquals(74, (float) $order->total);
        $this->assertEquals(74, $this->balance());
        $this->assertEquals(92, $this->stock());
    }

    public function test_create_merges_a_typed_price_equal_to_the_default_price(): void
    {
        $order = $this->createOrder([
            ['quantity' => 5],
            ['quantity' => 3, 'unit_price' => '10.00'],
        ]);

        $this->assertEquals(1, $order->items()->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 8, 'unit_price' => 10]);
        $this->assertEquals(80, (float) $order->total);
    }

    public function test_create_keeps_different_units_separate_at_the_same_price(): void
    {
        $order = $this->createOrder([
            ['quantity' => 1, 'unit_type' => 'secondary', 'unit_price' => 120],
            ['quantity' => 2, 'unit_type' => 'base', 'unit_price' => 120],
        ]);

        $this->assertEquals(2, $order->items()->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'unit_type' => 'secondary', 'quantity' => 1]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'unit_type' => 'base', 'quantity' => 2]);
        $this->assertEquals(360, (float) $order->total);
        $this->assertEquals(86, $this->stock());
    }

    public function test_add_item_merges_into_the_line_with_the_same_price(): void
    {
        $order = $this->createOrder([['quantity' => 5, 'unit_price' => 10]]);

        $this->addItem($order, ['quantity' => 3, 'unit_price' => 10]);

        $this->assertEquals(1, $order->items()->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 8, 'unit_price' => 10]);
        $this->assertEquals(80, (float) $order->fresh()->total);
        $this->assertEquals(80, $this->balance());
        $this->assertEquals(92, $this->stock());
    }

    public function test_add_item_with_a_different_price_creates_a_separate_line(): void
    {
        $order = $this->createOrder([['quantity' => 5, 'unit_price' => 10]]);

        $this->addItem($order, ['quantity' => 3, 'unit_price' => 8]);

        $this->assertEquals(2, $order->items()->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 5, 'unit_price' => 10]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 3, 'unit_price' => 8]);
        $this->assertEquals(74, (float) $order->fresh()->total);
        $this->assertEquals(74, $this->balance());
        $this->assertEquals(92, $this->stock());
    }

    public function test_add_item_without_a_price_merges_into_the_default_price_line(): void
    {
        $order = $this->createOrder([['quantity' => 5]]);

        $this->addItem($order, ['quantity' => 3]);

        $this->assertEquals(1, $order->items()->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 8, 'unit_price' => 10]);
        $this->assertEquals(80, (float) $order->fresh()->total);
    }

    public function test_add_item_after_a_price_change_snapshots_the_new_price_on_its_own_line(): void
    {
        $order = $this->createOrder([['quantity' => 5]]);

        $this->product->update(['price' => 12]);

        $this->addItem($order, ['quantity' => 3]);

        $this->assertEquals(2, $order->items()->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 5, 'unit_price' => 10]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 3, 'unit_price' => 12]);
        $this->assertEquals(86, (float) $order->fresh()->total);
        $this->assertEquals(86, $this->balance());
        $this->assertEquals(92, $this->stock());
    }

    public function test_add_item_after_a_tier_change_snapshots_the_new_tier_price_on_its_own_line(): void
    {
        $this->product->update(['price_a' => 9]);
        $order = $this->createOrder([['quantity' => 5]]);

        $this->customer->update(['price_tier' => 'a']);

        $this->addItem($order, ['quantity' => 3]);

        $this->assertEquals(2, $order->items()->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 5, 'unit_price' => 10]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 3, 'unit_price' => 9]);
        $this->assertEquals(77, (float) $order->fresh()->total);
        $this->assertEquals(77, $this->balance());
    }

    public function test_add_item_picks_the_line_whose_price_matches(): void
    {
        $order = $this->createOrder([
            ['quantity' => 5, 'unit_price' => 10],
            ['quantity' => 3, 'unit_price' => 8],
        ]);

        $this->addItem($order, ['quantity' => 2, 'unit_price' => 8]);

        $this->assertEquals(2, $order->items()->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 5, 'unit_price' => 10]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 5, 'unit_price' => 8]);
        $this->assertEquals(90, (float) $order->fresh()->total);
        $this->assertEquals(90, $this->balance());
        $this->assertEquals(90, $this->stock());
    }
}
