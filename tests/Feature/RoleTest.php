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
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->actingAs($this->admin)
            ->deleteJson("/api/products/{$product->id}")
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
                'direction' => 'in',
                'notes' => 'Test adjustment',
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
                'direction' => 'in',
                'notes' => 'Test adjustment',
            ])->assertStatus(403);
    }

    // ─────────────────────────────────────────
    // ORDER DELETION — store scoped for managers
    // ─────────────────────────────────────────

    private function createOrderForStore(Store $store, User $actor): int
    {
        return $this->actingAs($actor)
            ->postJson('/api/orders', [
                'store_id'    => $store->id,
                'customer_id' => $this->customer->id,
                'order_date'  => now()->toDateString(),
                'items'       => [
                    [
                        'product_id'   => $this->product->id,
                        'quantity'     => 1,
                        'warehouse_id' => $this->warehouse->id,
                    ],
                ],
            ])->assertStatus(201)->json('id');
    }

    public function test_manager_can_delete_own_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->store, $this->manager);

        $this->actingAs($this->manager)
            ->deleteJson("/api/orders/{$orderId}")
            ->assertStatus(200);

        $this->assertSoftDeleted('orders', ['id' => $orderId]);
    }

    public function test_manager_cannot_delete_other_store_order(): void
    {
        $otherStore = Store::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Other Store',
            'address'   => 'Other Address',
            'phone'     => '01000000002',
        ]);

        $orderId = $this->createOrderForStore($otherStore, $this->admin);

        $this->actingAs($this->manager)
            ->deleteJson("/api/orders/{$orderId}")
            ->assertStatus(403);

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'deleted_at' => null]);
    }

    public function test_admin_can_delete_any_store_order(): void
    {
        $otherStore = Store::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Admin Other Store',
            'address'   => 'Other Address',
            'phone'     => '01000000003',
        ]);

        $orderId = $this->createOrderForStore($otherStore, $this->admin);

        $this->actingAs($this->admin)
            ->deleteJson("/api/orders/{$orderId}")
            ->assertStatus(200);

        $this->assertSoftDeleted('orders', ['id' => $orderId]);
    }

    public function test_staff_cannot_delete_own_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->store, $this->staff);

        $this->actingAs($this->staff)
            ->deleteJson("/api/orders/{$orderId}")
            ->assertStatus(403);

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'deleted_at' => null]);
    }

    // ─────────────────────────────────────────
    // ORDER VIEW / UPDATE — store scoped
    // ─────────────────────────────────────────

    private function otherStore(string $phone): Store
    {
        return Store::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Other Store ' . $phone,
            'address'   => 'Other Address',
            'phone'     => $phone,
        ]);
    }

    public function test_manager_can_view_own_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->store, $this->manager);

        $this->actingAs($this->manager)
            ->getJson("/api/orders/{$orderId}")
            ->assertStatus(200);
    }

    public function test_manager_cannot_view_other_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->otherStore('01000000010'), $this->admin);

        $this->actingAs($this->manager)
            ->getJson("/api/orders/{$orderId}")
            ->assertStatus(403);
    }

    public function test_staff_cannot_view_other_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->otherStore('01000000011'), $this->admin);

        $this->actingAs($this->staff)
            ->getJson("/api/orders/{$orderId}")
            ->assertStatus(403);
    }

    public function test_admin_can_view_any_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->otherStore('01000000012'), $this->admin);

        $this->actingAs($this->admin)
            ->getJson("/api/orders/{$orderId}")
            ->assertStatus(200);
    }

    public function test_staff_can_update_own_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->store, $this->staff);

        $this->actingAs($this->staff)
            ->patchJson("/api/orders/{$orderId}", ['notes' => 'Same store edit'])
            ->assertStatus(200);

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'notes' => 'Same store edit']);
    }

    public function test_staff_cannot_update_other_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->otherStore('01000000013'), $this->admin);

        $this->actingAs($this->staff)
            ->patchJson("/api/orders/{$orderId}", ['notes' => 'Cross store edit'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('orders', ['id' => $orderId, 'notes' => 'Cross store edit']);
    }

    public function test_manager_cannot_update_other_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->otherStore('01000000014'), $this->admin);

        $this->actingAs($this->manager)
            ->patchJson("/api/orders/{$orderId}", ['notes' => 'Cross store edit'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('orders', ['id' => $orderId, 'notes' => 'Cross store edit']);
    }

    public function test_manager_cannot_add_item_to_other_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->otherStore('01000000015'), $this->admin);

        $this->actingAs($this->manager)
            ->postJson("/api/orders/{$orderId}/items", [
                'product_id'   => $this->product->id,
                'quantity'     => 1,
                'warehouse_id' => $this->warehouse->id,
            ])
            ->assertStatus(403);
    }

    public function test_admin_can_update_any_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->otherStore('01000000016'), $this->admin);

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$orderId}", ['notes' => 'Admin edit'])
            ->assertStatus(200);

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'notes' => 'Admin edit']);
    }

    // ─────────────────────────────────────────
    // PAYMENTS — order must belong to the user's store
    // ─────────────────────────────────────────

    private function payOrder(int $orderId, User $actor, float $amount = 100)
    {
        return $this->actingAs($actor)->postJson('/api/payments', [
            'order_id'    => $orderId,
            'customer_id' => $this->customer->id,
            'amount'      => $amount,
            'method'      => 'cash',
        ]);
    }

    public function test_staff_can_pay_own_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->store, $this->staff);

        $this->payOrder($orderId, $this->staff)->assertStatus(201);

        $this->assertDatabaseHas('payments', ['order_id' => $orderId, 'amount' => 100]);
    }

    public function test_staff_cannot_pay_other_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->otherStore('01000000020'), $this->admin);

        $this->payOrder($orderId, $this->staff)
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_id');

        $this->assertDatabaseMissing('payments', ['order_id' => $orderId]);
    }

    public function test_manager_cannot_pay_other_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->otherStore('01000000021'), $this->admin);

        $this->payOrder($orderId, $this->manager)
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_id');

        $this->assertDatabaseMissing('payments', ['order_id' => $orderId]);
    }

    public function test_admin_can_pay_any_store_order(): void
    {
        $orderId = $this->createOrderForStore($this->otherStore('01000000022'), $this->admin);

        $this->payOrder($orderId, $this->admin)->assertStatus(201);

        $this->assertDatabaseHas('payments', ['order_id' => $orderId, 'amount' => 100]);
    }

    public function test_payment_rejects_order_from_another_tenant(): void
    {
        $otherTenant   = Tenant::create(['name' => 'Other Tenant']);
        $otherStore    = Store::create([
            'tenant_id' => $otherTenant->id,
            'name'      => 'Foreign Store',
            'address'   => 'Foreign Address',
            'phone'     => '01000000023',
        ]);
        $otherAdmin    = User::create([
            'tenant_id' => $otherTenant->id,
            'name'      => 'Foreign Admin',
            'email'     => 'foreign@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'tenant_admin',
            'store_id'  => null,
        ]);
        $otherCustomer = Customer::create([
            'tenant_id' => $otherTenant->id,
            'name'      => 'Foreign Customer',
            'phone'     => '01000000024',
        ]);
        $otherProduct  = Product::create([
            'tenant_id' => $otherTenant->id,
            'name'      => 'Foreign Product',
            'sku'       => 'SKU-F1',
            'price'     => 100,
            'unit'      => 'pcs',
        ]);
        $otherWarehouse = Warehouse::create([
            'tenant_id' => $otherTenant->id,
            'store_id'  => $otherStore->id,
            'name'      => 'Foreign Warehouse',
        ]);
        Inventory::create([
            'tenant_id'    => $otherTenant->id,
            'warehouse_id' => $otherWarehouse->id,
            'product_id'   => $otherProduct->id,
            'quantity'     => 100,
            'threshold'    => 1,
        ]);

        $foreignOrderId = $this->actingAs($otherAdmin)->postJson('/api/orders', [
            'store_id'    => $otherStore->id,
            'customer_id' => $otherCustomer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [[
                'product_id'   => $otherProduct->id,
                'quantity'     => 1,
                'warehouse_id' => $otherWarehouse->id,
            ]],
        ])->assertStatus(201)->json('id');

        $this->payOrder($foreignOrderId, $this->admin)
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_id');

        $this->assertDatabaseMissing('payments', ['order_id' => $foreignOrderId]);
    }

    public function test_payment_rejects_soft_deleted_order(): void
    {
        $orderId = $this->createOrderForStore($this->store, $this->admin);

        $this->actingAs($this->admin)
            ->deleteJson("/api/orders/{$orderId}")
            ->assertStatus(200);

        $this->payOrder($orderId, $this->admin)
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_id');
    }

    // ─────────────────────────────────────────
    // REFUNDS — order must belong to the user's store
    // ─────────────────────────────────────────

    private function paidOrderForStore(Store $store, User $payer): int
    {
        $orderId = $this->createOrderForStore($store, $this->admin);
        $this->payOrder($orderId, $payer)->assertStatus(201);

        return $orderId;
    }

    private function refund(int $orderId, User $actor)
    {
        return $this->actingAs($actor)->postJson("/api/customers/{$this->customer->id}/refund", [
            'amount'   => 50,
            'method'   => 'cash',
            'order_id' => $orderId,
        ]);
    }

    public function test_staff_can_refund_own_store_order(): void
    {
        $orderId = $this->paidOrderForStore($this->store, $this->staff);

        $this->refund($orderId, $this->staff)->assertStatus(200);
    }

    public function test_staff_cannot_refund_other_store_order(): void
    {
        $orderId = $this->paidOrderForStore($this->otherStore('01000000030'), $this->admin);

        $this->refund($orderId, $this->staff)
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_id');
    }

    public function test_manager_cannot_refund_other_store_order(): void
    {
        $orderId = $this->paidOrderForStore($this->otherStore('01000000031'), $this->admin);

        $this->refund($orderId, $this->manager)
            ->assertStatus(422)
            ->assertJsonValidationErrors('order_id');
    }

    public function test_admin_can_refund_any_store_order(): void
    {
        $orderId = $this->paidOrderForStore($this->otherStore('01000000032'), $this->admin);

        $this->refund($orderId, $this->admin)->assertStatus(200);
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

public function test_staff_cannot_update_product(): void
{
    $this->actingAs($this->staff)
        ->putJson("/api/products/{$this->product->id}", [
            'name'  => 'Renamed',
            'sku'   => 'SKU-001',
            'price' => 999,
            'unit'  => 'pcs',
        ])->assertStatus(403);

    $this->assertDatabaseHas('products', [
        'id'    => $this->product->id,
        'name'  => 'Test Product',
        'price' => 100,
    ]);
}

/** A duplicate is just another create — the same door, and it stays shut. */
public function test_staff_cannot_duplicate_an_existing_product(): void
{
    $this->actingAs($this->staff)
        ->postJson('/api/products', [
            'name'  => $this->product->name,
            'sku'   => $this->product->sku . '-COPY',
            'price' => $this->product->price,
            'unit'  => $this->product->unit,
        ])->assertStatus(403);

    $this->assertDatabaseCount('products', 1);
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
            'order_date'  => now()->toDateString(),
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
    $this->actingAs($this->staff)
        ->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 1,
                    'warehouse_id' => $this->warehouse->id,
                ],
            ],
        ]);

    $this->actingAs($this->staff)
        ->postJson('/api/payments/auto', [
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
            'direction' => 'in',
            'notes' => 'Test adjustment',
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

// ─────────────────────────────────────────
// PAYMENT-LOCK TIERS — order edits by payment state
// ─────────────────────────────────────────

public function test_staff_cannot_modify_partially_paid_order(): void
{
    $order = $this->actingAs($this->staff)->postJson('/api/orders', [
        'store_id'    => $this->store->id,
        'customer_id' => $this->customer->id,
        'order_date'  => now()->toDateString(),
        'items'       => [
            ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id],
        ],
    ])->assertStatus(201)->json();

    // Partial payment: 50 of 200 owed
    $this->actingAs($this->staff)->postJson('/api/payments', [
        'order_id'    => $order['id'],
        'customer_id' => $this->customer->id,
        'amount'      => 50,
        'method'      => 'cash',
    ])->assertStatus(201);

    $this->actingAs($this->staff)
        ->patchJson("/api/orders/{$order['id']}/items/{$order['items'][0]['id']}", ['quantity' => 3])
        ->assertStatus(422);
}

public function test_manager_can_modify_partially_paid_order(): void
{
    $order = $this->actingAs($this->manager)->postJson('/api/orders', [
        'store_id'    => $this->store->id,
        'customer_id' => $this->customer->id,
        'order_date'  => now()->toDateString(),
        'items'       => [
            ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id],
        ],
    ])->assertStatus(201)->json();

    $this->actingAs($this->manager)->postJson('/api/payments', [
        'order_id'    => $order['id'],
        'customer_id' => $this->customer->id,
        'amount'      => 50,
        'method'      => 'cash',
    ])->assertStatus(201);

    $this->actingAs($this->manager)
        ->patchJson("/api/orders/{$order['id']}/items/{$order['items'][0]['id']}", ['quantity' => 3])
        ->assertStatus(200);
}
}