<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;

class ProductTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;

public function test_can_update_product_without_changing_sku(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => 'tenant_admin',
        'store_id' => null,
    ]);
    $product = Product::factory()->create([
        'tenant_id' => $tenant->id,
        'sku' => 'TEST-001',
    ]);

    $response = $this->actingAs($user)->putJson("/api/products/{$product->id}", [
        'name' => 'Updated Name',
        'sku' => 'TEST-001',
        'price' => 100,
        'unit' => 'حبة',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Updated Name',
        'sku' => 'TEST-001',
    ]);
}

public function test_cannot_delete_product_with_order_history(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => 'tenant_admin',
        'store_id' => null,
    ]);
    $store = Store::factory()->create(['tenant_id' => $tenant->id]);
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
    // No inventory row at all — zero current stock, so the stock check wouldn't
    // block this; ProductObserver::deleting's orderItems() check should.
    $product = Product::factory()->create(['tenant_id' => $tenant->id]);
    $order = Order::factory()->create([
        'tenant_id'   => $tenant->id,
        'store_id'    => $store->id,
        'customer_id' => $customer->id,
        'total'       => 100,
    ]);
    OrderItem::factory()->create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'unit_price' => 100,
        'quantity'   => 1,
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/products/{$product->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete a product that appears in existing orders.');

    $this->assertDatabaseHas('products', ['id' => $product->id]);
    $this->assertDatabaseHas('order_items', ['product_id' => $product->id]);
}

public function test_increasing_stock_via_product_edit_logs_inventory_transaction(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => 'tenant_admin',
        'store_id' => null,
    ]);
    $store = Store::factory()->create(['tenant_id' => $tenant->id]);
    $warehouse = Warehouse::factory()->create(['tenant_id' => $tenant->id, 'store_id' => $store->id]);
    $product = Product::factory()->create(['tenant_id' => $tenant->id]);
    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity' => 50,
        'threshold' => 10,
    ]);

    $this->actingAs($user)->putJson("/api/products/{$product->id}", [
        'name' => $product->name,
        'sku' => (string) $product->sku,
        'price' => $product->price,
        'unit' => $product->unit,
        'stocks' => [
            ['warehouse_id' => $warehouse->id, 'quantity' => 65],
        ],
    ])->assertOk();

    $this->assertDatabaseHas('inventory', [
        'id' => $inventory->id,
        'quantity' => 65,
    ]);
    $this->assertDatabaseHas('inventory_transactions', [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity' => 15,
        'type' => 'ADJUSTMENT_IN',
        'user_id' => $user->id,
    ]);
}

public function test_decreasing_stock_via_product_edit_logs_inventory_transaction(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => 'tenant_admin',
        'store_id' => null,
    ]);
    $store = Store::factory()->create(['tenant_id' => $tenant->id]);
    $warehouse = Warehouse::factory()->create(['tenant_id' => $tenant->id, 'store_id' => $store->id]);
    $product = Product::factory()->create(['tenant_id' => $tenant->id]);
    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity' => 50,
        'threshold' => 10,
    ]);

    $this->actingAs($user)->putJson("/api/products/{$product->id}", [
        'name' => $product->name,
        'sku' => (string) $product->sku,
        'price' => $product->price,
        'unit' => $product->unit,
        'stocks' => [
            ['warehouse_id' => $warehouse->id, 'quantity' => 30],
        ],
    ])->assertOk();

    $this->assertDatabaseHas('inventory', [
        'id' => $inventory->id,
        'quantity' => 30,
    ]);
    $this->assertDatabaseHas('inventory_transactions', [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity' => 20,
        'type' => 'ADJUSTMENT_OUT',
        'user_id' => $user->id,
    ]);
}

public function test_adding_new_warehouse_stock_via_product_edit_logs_inventory_transaction(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => 'tenant_admin',
        'store_id' => null,
    ]);
    $store = Store::factory()->create(['tenant_id' => $tenant->id]);
    $warehouse = Warehouse::factory()->create(['tenant_id' => $tenant->id, 'store_id' => $store->id]);
    $product = Product::factory()->create(['tenant_id' => $tenant->id]);

    // No inventory row for this warehouse yet — the stocks[] editor is creating one for the
    // first time, which should still log the initial quantity as an audited transaction.
    $this->actingAs($user)->putJson("/api/products/{$product->id}", [
        'name' => $product->name,
        'sku' => (string) $product->sku,
        'price' => $product->price,
        'unit' => $product->unit,
        'stocks' => [
            ['warehouse_id' => $warehouse->id, 'quantity' => 20, 'threshold' => 5],
        ],
    ])->assertOk();

    $this->assertDatabaseHas('inventory', [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity' => 20,
        'threshold' => 5,
    ]);
    $this->assertDatabaseHas('inventory_transactions', [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity' => 20,
        'type' => 'ADJUSTMENT_IN',
        'user_id' => $user->id,
    ]);
}

public function test_editing_product_stocks_without_quantity_only_updates_threshold(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => 'tenant_admin',
        'store_id' => null,
    ]);
    $store = Store::factory()->create(['tenant_id' => $tenant->id]);
    $warehouse = Warehouse::factory()->create(['tenant_id' => $tenant->id, 'store_id' => $store->id]);
    $product = Product::factory()->create(['tenant_id' => $tenant->id]);
    $inventory = Inventory::factory()->create([
        'tenant_id' => $tenant->id,
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity' => 50,
        'threshold' => 10,
    ]);

    $this->actingAs($user)->putJson("/api/products/{$product->id}", [
        'name' => $product->name,
        'sku' => (string) $product->sku,
        'price' => $product->price,
        'unit' => $product->unit,
        'stocks' => [
            ['warehouse_id' => $warehouse->id, 'threshold' => 25],
        ],
    ])->assertOk();

    $this->assertDatabaseHas('inventory', [
        'id' => $inventory->id,
        'quantity' => 50,
        'threshold' => 25,
    ]);
    $this->assertDatabaseMissing('inventory_transactions', [
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
    ]);
}
}
