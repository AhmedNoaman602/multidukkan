<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;

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
}
