<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Tenant;
use App\Models\Store;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Inventory;
use App\Models\User;
class WarehouseTest extends TestCase
{
    /**
     * A basic feature test example.
     */
      use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Product $product;
    protected Warehouse $warehouse;
    protected Inventory $inventory;
    protected User $user;
  protected function setUp(): void
    {
        parent::setUp();

        $this->tenant    = Tenant::factory()->create();
        $this->store     = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product   = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 100]);
        $this->warehouse = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        $this->inventory = Inventory::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => 50,
            'threshold'    => 10,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
    }
      public function test_can_create_warehouse(): void {

            $response = $this->actingAs($this->user)->postJson('/api/warehouses' , [
                'store_id'=>$this->store->id,
                'name'=>'Main Warehouse',
            ])->assertStatus(201);

            $this->assertDatabaseHas('warehouses', [
                'tenant_id'=>$this->tenant->id,
                'store_id'=>$this->store->id,
                'name'=>'Main Warehouse',
            ]);
        }

        public function test_can_list_warehouses(): void {
            $this->actingAs($this->user)
                 ->getJson("/api/warehouses")
                 ->assertStatus(200)
                 ->assertJsonCount(1, 'data');
        }
        
        public function test_can_update_warehouse() : void{
            $this->actingAs($this->user)->putJson("/api/warehouses/{$this->warehouse->id}", [
                'store_id'=>$this->store->id,   
                'name'=>'Updated Warehouse',
            ])->assertStatus(200);

            $this->assertDatabaseHas('warehouses', [
                'id'=>$this->warehouse->id,
                'name'=>'Updated Warehouse',
            ]);
        }
      public function  test_can_delete_warehouse_with_no_stock() : void{
        $this->inventory->update([
            'quantity'=>0,
        ]);
        $this->actingAs($this->user)->deleteJson("/api/warehouses/{$this->warehouse->id}")
        ->assertStatus(200);

        $this->assertDatabaseMissing('warehouses',[
            'id'=>$this->warehouse->id,
        ]);
    }

    public function test_cannot_delete_warehouse_with_existing_stock() : void{
        $this->actingAs($this->user)->deleteJson("/api/warehouses/{$this->warehouse->id}")
        ->assertStatus(422);

        $this->assertDatabaseHas('warehouses',[
            'id'=>$this->warehouse->id,
        ]);
    }

    public function test_cannot_create_warehouse_with_store_from_different_tenant(): void{
        $otherTenant = Tenant::factory()->create();
        $otherStore = Store::factory()->create(['tenant_id' => $otherTenant->id]);
        $this->actingAs($this->user)->postJson("/api/warehouses", [
            'store_id'=>$otherStore->id,
            'name'=>'Warehouse',
        ])->assertStatus(422);
    }
}