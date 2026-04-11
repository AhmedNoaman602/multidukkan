<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Store;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Warehouse;
use App\Models\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private User $admin;
    private User $manager;
    private Product $product;
    private Customer $customer;
    private Warehouse $warehouse;
    private Inventory $inventory;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Tenant']);

       $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Test Store',
            'address'   => 'Test Address',
            'phone'     => '01000000000',
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Admin User',
            'email'     => 'admin@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'tenant_admin',
            'store_id'  => null,
        ]);

        $this->manager = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Manager User',
            'email'     => 'manager@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'store_manager',
            'store_id'  => $this->store->id,
        ]);

        $this->staff = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Staff User',
            'email'     => 'staff@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'store_staff',
            'store_id'  => $this->store->id,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Test Product',
            'sku'       => 'SKU-001',
            'price'     => 100,
            'unit'      => 'pcs',
        ]);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Test Customer',
            'phone'     => '01000000000',
        ]);

        $this->warehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
            'name'      => 'Main Warehouse',
        ]);

        $this->inventory = Inventory::create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => 100,
            'threshold'    => 10,
        ]);
    }

    // ─────────────────────────────────────────
    // PRODUCTS — admin only
    // ─────────────────────────────────────────

    public function test_admin_can_create_product(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/products', [
                'name'  => 'New Product',
                'sku'   => 'SKU-002',
                'price' => 50,
                'unit'  => 'pcs',
            ])->assertStatus(201);
    }

    public function test_manager_cannot_create_product(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/products', [
                'name'  => 'New Product',
                'sku'   => 'SKU-002',
                'price' => 50,
                'unit'  => 'pcs',
            ])->assertStatus(403);
    }

    public function test_admin_can_delete_product(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson("/api/products/{$this->product->id}")
            ->assertStatus(200);
    }

    public function test_manager_cannot_delete_product(): void
    {
        $this->actingAs($this->manager)
            ->deleteJson("/api/products/{$this->product->id}")
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────
    // CUSTOMERS — delete is admin only
    // ─────────────────────────────────────────

    public function test_admin_can_delete_customer(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson("/api/customers/{$this->customer->id}")
            ->assertStatus(200);
    }

    public function test_manager_cannot_delete_customer(): void
    {
        $this->actingAs($this->manager)
            ->deleteJson("/api/customers/{$this->customer->id}")
            ->assertStatus(403);
    }

    public function test_manager_can_create_customer(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/customers', [
                'name'  => 'New Customer',
                'phone' => '01111111111',
            ])->assertStatus(201);
    }

    // ─────────────────────────────────────────
    // STORES — admin only
    // ─────────────────────────────────────────

   public function test_admin_can_create_store(): void
{
    $this->actingAs($this->admin)
        ->postJson('/api/stores', [
            'name'    => 'New Store',
            'address' => 'New Address',
            'phone'   => '01000000002',
        ])->assertStatus(201);
}

    public function test_manager_cannot_create_store(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/stores', [
                'name' => 'New Store',
            ])->assertStatus(403);
    }

    // ─────────────────────────────────────────
    // INVENTORY — scoped to own store
    // ─────────────────────────────────────────

    public function test_manager_can_adjust_own_store_inventory(): void
    {
        $this->actingAs($this->manager)
            ->postJson("/api/inventory/{$this->inventory->id}/adjust", [
                'quantity' => 10,
            ])->assertStatus(200);
    }

    public function test_manager_cannot_adjust_other_store_inventory(): void
    {
       $otherStore = Store::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Other Store',
            'address'   => 'Other Address',
            'phone'     => '01000000001',
        ]);

        $otherWarehouse = Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $otherStore->id,
            'name'      => 'Other Warehouse',
        ]);

        $otherInventory = Inventory::create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $otherWarehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => 50,
            'threshold'    => 5,
        ]);

        $this->actingAs($this->manager)
            ->postJson("/api/inventory/{$otherInventory->id}/adjust", [
                'quantity' => 10,
            ])->assertStatus(403);
    }

    // ─────────────────────────────────────────
// STORE STAFF
// ─────────────────────────────────────────

public function test_staff_can_view_products(): void
{
    $this->actingAs($this->staff)
        ->getJson('/api/products')
        ->assertStatus(200);
}

public function test_staff_cannot_create_product(): void
{
    $this->actingAs($this->staff)
        ->postJson('/api/products', [
            'name'  => 'New Product',
            'sku'   => 'SKU-STAFF',
            'price' => 50,
            'unit'  => 'pcs',
        ])->assertStatus(403);
}

public function test_staff_can_create_customer(): void
{
    $this->actingAs($this->staff)
        ->postJson('/api/customers', [
            'name'  => 'Staff Customer',
            'phone' => '01222222222',
        ])->assertStatus(201);
}

public function test_staff_cannot_delete_customer(): void
{
    $this->actingAs($this->staff)
        ->deleteJson("/api/customers/{$this->customer->id}")
        ->assertStatus(403);
}

public function test_staff_can_create_order(): void
{
    $this->actingAs($this->staff)
        ->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'items'       => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 1,
                    'warehouse_id' => $this->warehouse->id,
                ],
            ],
        ])->assertStatus(201);
}

public function test_staff_can_process_payment(): void
{
    $order = $this->actingAs($this->staff)
        ->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'items'       => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 1,
                    'warehouse_id' => $this->warehouse->id,
                ],
            ],
        ])->json('id');

    $this->actingAs($this->staff)
        ->postJson('/api/payments', [
            'order_id'    => $order,
            'customer_id' => $this->customer->id,
            'amount'      => 100,
            'method'      => 'cash',
        ])->assertStatus(201);
}

public function test_staff_cannot_adjust_inventory(): void
{
    $this->actingAs($this->staff)
        ->postJson("/api/inventory/{$this->inventory->id}/adjust", [
            'quantity' => 10,
        ])->assertStatus(403);
}

public function test_staff_cannot_create_store(): void
{
    $this->actingAs($this->staff)
        ->postJson('/api/stores', [
            'name'    => 'Staff Store',
            'address' => 'Some Address',
            'phone'   => '01333333333',
        ])->assertStatus(403);
}

public function test_staff_cannot_create_warehouse(): void
{
    $this->actingAs($this->staff)
        ->postJson('/api/warehouses', [
            'store_id' => $this->store->id,
            'name'     => 'Staff Warehouse',
        ])->assertStatus(403);
}
}