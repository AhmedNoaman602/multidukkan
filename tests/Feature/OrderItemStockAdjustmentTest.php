<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderItemStockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Customer $customer;
    protected Product $product;
    protected User $user;
    protected Warehouse $warehouse;
    protected Inventory $inventory;

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
        $this->user = User::factory()->create([
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

    private function createOrder(int $quantity, string $unitType): Order
    {
        $response = $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [[
                'product_id'   => $this->product->id,
                'quantity'     => $quantity,
                'unit_type'    => $unitType,
            ]],
        ])->assertStatus(201);

        return Order::findOrFail($response->json('id'));
    }

    private function adjustQuantity(Order $order, int $quantity): \Illuminate\Testing\TestResponse
    {
        $item = $order->items()->firstOrFail();

        return $this->actingAs($this->user)
            ->patchJson("/api/orders/{$order->id}/items/{$item->id}", ['quantity' => $quantity]);
    }

    private function latestTransaction(Order $order): InventoryTransaction
    {
        return InventoryTransaction::where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    public function test_increasing_a_secondary_unit_item_deducts_the_base_unit_delta(): void
    {
        $order = $this->createOrder(2, 'secondary');
        $this->assertEquals(76, $this->inventory->fresh()->quantity);

        $this->adjustQuantity($order, 3)->assertStatus(200);

        $this->assertEquals(64, $this->inventory->fresh()->quantity);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 3, 'unit_type' => 'secondary']);

        $transaction = $this->latestTransaction($order);
        $this->assertEquals(InventoryTransaction::TYPE_SALE, $transaction->type);
        $this->assertEquals(12, $transaction->quantity);
    }

    public function test_decreasing_a_secondary_unit_item_restores_the_base_unit_delta(): void
    {
        $order = $this->createOrder(3, 'secondary');
        $this->assertEquals(64, $this->inventory->fresh()->quantity);

        $this->adjustQuantity($order, 2)->assertStatus(200);

        $this->assertEquals(76, $this->inventory->fresh()->quantity);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 2, 'unit_type' => 'secondary']);

        $transaction = $this->latestTransaction($order);
        $this->assertEquals(InventoryTransaction::TYPE_RETURN, $transaction->type);
        $this->assertEquals(12, $transaction->quantity);
    }

    public function test_increasing_a_base_unit_item_deducts_the_raw_delta(): void
    {
        $order = $this->createOrder(2, 'base');
        $this->assertEquals(98, $this->inventory->fresh()->quantity);

        $this->adjustQuantity($order, 5)->assertStatus(200);

        $this->assertEquals(95, $this->inventory->fresh()->quantity);

        $transaction = $this->latestTransaction($order);
        $this->assertEquals(InventoryTransaction::TYPE_SALE, $transaction->type);
        $this->assertEquals(3, $transaction->quantity);
    }

    public function test_decreasing_a_base_unit_item_restores_the_raw_delta(): void
    {
        $order = $this->createOrder(5, 'base');
        $this->assertEquals(95, $this->inventory->fresh()->quantity);

        $this->adjustQuantity($order, 2)->assertStatus(200);

        $this->assertEquals(98, $this->inventory->fresh()->quantity);

        $transaction = $this->latestTransaction($order);
        $this->assertEquals(InventoryTransaction::TYPE_RETURN, $transaction->type);
        $this->assertEquals(3, $transaction->quantity);
    }

    public function test_secondary_unit_increase_is_rejected_when_base_stock_is_insufficient(): void
    {
        $this->inventory->update(['quantity' => 30]);

        $order = $this->createOrder(2, 'secondary');
        $this->assertEquals(6, $this->inventory->fresh()->quantity);

        $this->adjustQuantity($order, 3)->assertStatus(422);

        $this->assertEquals(6, $this->inventory->fresh()->quantity);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 2]);
        $this->assertEquals(1, InventoryTransaction::where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->count());
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total' => 120]);
    }
}
