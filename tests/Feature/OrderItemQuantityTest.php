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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Order quantities are whole numbers. A fractional quantity must be rejected before anything is
// saved: accepting it once stored a rounded quantity and charge but moved a truncated amount of stock.
class OrderItemQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Customer $customer;
    private Product $product;
    private User $admin;
    private Warehouse $shelf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 100]);
        $this->admin    = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->shelf = Warehouse::factory()->shelf()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->shelf->id,
            'product_id'   => $this->product->id,
            'quantity'     => 100,
        ]);
    }

    public function test_creating_an_order_with_a_fractional_quantity_is_rejected(): void
    {
        $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => 1.5]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');

        $this->assertSame(0, Order::count());
        $this->assertEquals(100, Inventory::where('product_id', $this->product->id)->sum('quantity'));
    }
}
