<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Tenant;
use App\Models\Store;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Inventory;
use App\Models\User;
class InventoryTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;
  protected function setUp(): void
    {
        parent::setUp();

        $this->tenant    = Tenant::factory()->create();
        $this->store     = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer  = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product   = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 100]);
        $this->warehouse = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        $this->inventory = Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => 50,
            'threshold'    => 10,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => null,
            'role' => 'tenant_admin',
        ]);
    }

    private function createOrder(int $quantity = 2 , bool $withWarehouse = true) : \Illuminate\Testing\TestResponse{
        $item = [
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'warehouse_id' => $this->warehouse->id,
        ];
        
        return $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'items' => [$item],
        ]);
    }

      public function test_can_create_inventory(): void {
        $newProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->actingAs($this->user)->postJson('/api/inventory', [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $newProduct->id,
            'quantity' => 30,
        ])->assertStatus(201);

        $this->assertDatabaseHas('inventory', [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $newProduct->id,
            'quantity' => 30,
        ]);
            
      }
      public function test_can_view_inventory() : void {
        $this->actingAs($this->user)->getJson("/api/inventory/{$this->inventory->id}")
            ->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $this->inventory->id,
                    'quantity' => 50,
                ]
            ]);
      }

      public function test_can_update_inventory() : void {
        $this->actingAs($this->user)->putJson("/api/inventory/{$this->inventory->id}", [
            'quantity' => 60,
        ])->assertStatus(200);

        $this->assertDatabaseHas('inventory', [
            'id' => $this->inventory->id,
            'quantity' => 60,
        ]);
      }
    
      public function test_stock_deducted_on_order_creation() : void {
        $response = $this->createOrder();
        $this->assertDatabaseHas('inventory', [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => 48,
        ]);
      }

      public function test_stock_restored_on_order_cancellation(): void {
       $response = $this->createOrder();
       $orderId = $response->json('id');

       $this->actingAs($this->user)->deleteJson("/api/orders/{$orderId}")->assertStatus(200);

       $this->assertDatabaseHas('inventory', [
            'warehouse_id' => $this->warehouse->id, 
            'product_id' => $this->product->id,
            'quantity' => 50,
       ]);
      }
        
      public function test_cannot_create_order_with_insufficient_stock() : void {
        $response = $this->createOrder(60);
        $response->assertStatus(422);
        $this->assertDatabaseHas('inventory', [
            'warehouse_id' => $this->warehouse->id, 
            'product_id' => $this->product->id,
            'quantity' => 50,
       ]);
      }

      public function test_inventory_transaction_created_on_sale() : void {
        $this->createOrder();
        $this->assertDatabaseHas('inventory_transactions', [
            'warehouse_id' => $this->warehouse->id, 
            'product_id' => $this->product->id,
            'quantity' => 2,
            'type' => 'SALE',
        ]);
      }

      public function test_inventory_transaction_created_on_return() : void {
        $response = $this->createOrder();
        $orderId = $response->json('id');

        $this->deleteJson("/api/orders/{$orderId}", [
            'tenant_id' => $this->tenant->id,
        ])->assertStatus(200);

        $this->assertDatabaseHas('inventory_transactions', [
            'warehouse_id' => $this->warehouse->id, 
            'product_id' => $this->product->id,
            'quantity' => 2,
            'type' => 'RETURN',
        ]);
      }

     public function test_low_stock_flag_is_true_when_quantity_below_threshold(): void
{
    $this->inventory->update(['quantity' => 5]); // threshold is 10

    $this->actingAs($this->user)->getJson("/api/inventory/{$this->inventory->id}")
        ->assertStatus(200)
        ->assertJson(['data' => ['low_stock' => true]]);
}

public function test_low_stock_flag_is_false_when_quantity_above_threshold(): void
{
    $this->inventory->update(['quantity' => 50]); // threshold is 10

    $this->actingAs($this->user)->getJson("/api/inventory/{$this->inventory->id}")
        ->assertStatus(200)
        ->assertJson(['data' => ['low_stock' => false]]);
}
        
public function test_can_adjust_stock_manually() : void {
    $this->actingAs($this->user)->postJson("/api/inventory/{$this->inventory->id}/adjust", [
        'quantity' => 5,
        'direction' => 'in',
    ])->assertStatus(200);

    $this->assertDatabaseHas('inventory', [
        'warehouse_id' => $this->warehouse->id, 
        'product_id' => $this->product->id,
        'quantity' => 55,
    ]);
}

public function test_cannot_adjust_stock_with_zero_quantity() : void {
    $this->actingAs($this->user)->postJson("/api/inventory/{$this->inventory->id}/adjust", [
        'quantity' => 0,
    ])->assertStatus(422);
}

public function test_cannot_adjust_stock_with_negative_quantity() : void {
    $this->actingAs($this->user)->postJson("/api/inventory/{$this->inventory->id}/adjust", [
        'quantity' => -5,
    ])->assertStatus(422);
}

public function test_inventory_transaction_created_on_adjustment():void{
    $this->actingAs($this->user)->postJson("/api/inventory/{$this->inventory->id}/adjust", [
        'quantity' => 5,
        'direction' => 'in',
    ])->assertStatus(200);

    $this->assertDatabaseHas('inventory_transactions', [
        'warehouse_id' => $this->warehouse->id, 
        'product_id' => $this->product->id,
        'quantity' => 5,
        'type' => 'ADJUSTMENT_IN',
    ]);
}

public function test_cannot_create_inventory_with_warehouse_from_different_tenant(): void {
    $otherTenant = Tenant::factory()->create();
    $otherWarehouse = Warehouse::factory()->create([
        'tenant_id' => $otherTenant->id,
        'store_id' => $this->store->id,
    ]);
    $this->actingAs($this->user)->postJson("/api/inventory", [
        'warehouse_id' => $otherWarehouse->id,
        'product_id' => $this->product->id,
        'quantity' => 10,
    ])->assertStatus(422);
}

public function test_cannot_create_inventory_with_product_from_different_tenant() : void {
    $otherTenant = Tenant::factory()->create();
    $otherProduct = Product::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);
    $this->actingAs($this->user)->postJson("/api/inventory", [
        'warehouse_id' => $this->warehouse->id,
        'product_id' => $otherProduct->id,
        'quantity' => 10,
    ])->assertStatus(422);
}

public function test_cannot_create_order_with_warehouse_from_different_tenant(): void {
    $otherTenant = Tenant::factory()->create();
    $otherWarehouse = Warehouse::factory()->create([
        'tenant_id' => $otherTenant->id,
        'store_id' => $this->store->id,
    ]);
    $this->actingAs($this->user)->postJson("/api/orders", [
        'store_id' => $this->store->id,
        'customer_id' => $this->customer->id,
        'items' => [
            [
                'product_id' => $this->product->id,
                'warehouse_id' => $otherWarehouse->id,
                'quantity' => 1,
            ],
        ],
    ])->assertStatus(422);
}

public function test_order_with_warehouse_deducts_stock(): void
{
    $this->createOrder(quantity: 10)
        ->assertStatus(201);

    $this->assertDatabaseHas('inventory', [
        'warehouse_id' => $this->warehouse->id,
        'product_id'   => $this->product->id,
        'quantity'     => 40, // 50 - 10
    ]);
}

public function test_cancelled_order_restores_stock(): void
{
    $response = $this->createOrder(quantity: 10);
    $orderId  = $response->json('id');

    $this->actingAs($this->user)->deleteJson("/api/orders/{$orderId}")->assertStatus(200);

    $this->assertDatabaseHas('inventory', [
        'warehouse_id' => $this->warehouse->id,
        'product_id'   => $this->product->id,
        'quantity'     => 50, // restored
    ]);
}

public function test_two_stores_can_sell_same_product_from_different_warehouses(): void
{
    // Store B setup
    $storeB     = Store::factory()->create(['tenant_id' => $this->tenant->id]);
    $warehouseB = Warehouse::factory()->create([
        'tenant_id' => $this->tenant->id,
        'store_id'  => $storeB->id,
    ]);
    Inventory::factory()->create([
        'tenant_id'    => $this->tenant->id,
        'warehouse_id' => $warehouseB->id,
        'product_id'   => $this->product->id,
        'quantity'     => 50,
        'threshold'    => 10,
    ]);

    // Store A sells 5 from Warehouse A
    $this->createOrder(quantity: 5);

    // Store B sells 5 from Warehouse B
    $this->actingAs($this->user)->postJson('/api/orders', [
        'store_id'    => $storeB->id,
        'customer_id' => $this->customer->id,
        'items'       => [[
            'product_id'   => $this->product->id,
            'quantity'     => 5,
            'warehouse_id' => $warehouseB->id,
        ]],
    ])->assertStatus(201);

    // Warehouse A untouched by Store B's sale
    $this->assertDatabaseHas('inventory', [
        'warehouse_id' => $this->warehouse->id,
        'product_id'   => $this->product->id,
        'quantity'     => 45, // 50 - 5
    ]);

    // Warehouse B untouched by Store A's sale
    $this->assertDatabaseHas('inventory', [
        'warehouse_id' => $warehouseB->id,
        'product_id'   => $this->product->id,
        'quantity'     => 45, // 50 - 5
    ]);
}
public function test_admin_can_add_stock_via_adjust()
{
    $admin = User::factory()->create(['role' => 'tenant_admin', 'store_id' => null]);

    $store = Store::factory()->create(['tenant_id' => $admin->tenant_id]);
    $warehouse = Warehouse::factory()->create([
        'tenant_id' => $admin->tenant_id,
        'store_id' => $store->id,
    ]);
    $product = Product::factory()->create(['tenant_id' => $admin->tenant_id]);
    $inventory = Inventory::factory()->create([
        'tenant_id' => $admin->tenant_id,
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'quantity' => 10,
    ]);

    $response = $this->actingAs($admin)->postJson("/api/inventory/{$inventory->id}/adjust", [
        'quantity' => 50,
        'direction' => 'in',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('inventory', ['id' => $inventory->id, 'quantity' => 60]);
}
public function test_can_remove_stock_via_adjust(): void
{
    $this->inventory->update(['quantity' => 100]);

    $this->actingAs($this->user)->postJson("/api/inventory/{$this->inventory->id}/adjust", [
        'quantity' => 30,
        'direction' => 'out',
    ])->assertStatus(200);

    $this->assertDatabaseHas('inventory', [
        'id' => $this->inventory->id,
        'quantity' => 70,
    ]);
    $this->assertDatabaseHas('inventory_transactions', [
        'type' => 'ADJUSTMENT_OUT',
        'quantity' => 30,
    ]);
}

public function test_cannot_remove_more_stock_than_available(): void
{
    $this->inventory->update(['quantity' => 10]);

    $this->actingAs($this->user)->postJson("/api/inventory/{$this->inventory->id}/adjust", [
        'quantity' => 50,
        'direction' => 'out',
    ])->assertStatus(422);

    $this->assertDatabaseHas('inventory', [
        'id' => $this->inventory->id,
        'quantity' => 10,
    ]);
}
}
