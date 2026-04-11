<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Inventory;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerTest extends TestCase
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
        'tenant_id' => $this->tenant->id,
        'price'     => 100,
    ]);
    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'store_id'  => null,
        'role'      => 'tenant_admin',
    ]);
    $this->warehouse = Warehouse::factory()->create([
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

    // ─────────────────────────────────────────
    // Helper — creates an order via API
    // ─────────────────────────────────────────
    private function createOrder(int $quantity = 2): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'notes'       => 'Test order',
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => $quantity,'warehouse_id' => $this->warehouse->id,],
            ],
        ]);
    }

    // ─────────────────────────────────────────
    // Helper — creates a payment via API
    // ─────────────────────────────────────────
    private function createPayment(int $orderId, float $amount = 100.00): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/payments', [
            'order_id'    => $orderId,
            'customer_id' => $this->customer->id,
            'amount'      => $amount,
            'method'      => 'cash',
        ]);
    }

    // ─────────────────────────────────────────
    // ORDER VALIDATION TESTS
    // ─────────────────────────────────────────
    public function test_cannot_create_order_with_no_items(): void
    {
        $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
        ])->assertStatus(422);
    }

    public function test_cannot_create_order_with_empty_items_array(): void
    {
        $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'items'       => [],
        ])->assertStatus(422);
    }

    public function test_cannot_create_order_with_invalid_store_id(): void
    {
        $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => 999,
            'customer_id' => $this->customer->id,
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ])->assertStatus(422);
    }

    public function test_cannot_create_order_with_invalid_customer_id(): void
    {
        $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => 999,
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ])->assertStatus(422);
    }

    public function test_cannot_create_order_with_invalid_product_id(): void
    {
        $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'items'       => [
                ['product_id' => 999, 'quantity' => 1],
            ],
        ])->assertStatus(422);
    }

    public function test_cannot_create_order_with_zero_quantity(): void
    {
        $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 0],
            ],
        ])->assertStatus(422);
    }

    public function test_cannot_create_order_with_negative_quantity(): void
    {
        $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => -1],
            ],
        ])->assertStatus(422);
    }

    public function test_cannot_create_order_with_customer_from_different_tenant(): void
    {
        $otherTenant   = Tenant::factory()->create();
        $otherCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $otherCustomer->id,
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ])->assertStatus(422);
    }

    public function test_cannot_create_order_with_store_from_different_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherStore  = Store::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $otherStore->id,
            'customer_id' => $this->customer->id,
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ])->assertStatus(422);
    }

    public function test_cannot_create_order_with_product_from_different_tenant(): void
    {
        $otherTenant  = Tenant::factory()->create();
        $otherProduct = Product::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($this->user)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'items'       => [
                ['product_id' => $otherProduct->id, 'quantity' => 1],
            ],
        ])->assertStatus(422);
    }

    // ─────────────────────────────────────────
    // ORDER LIFECYCLE TESTS
    // ─────────────────────────────────────────
    public function test_cannot_delete_already_deleted_order(): void
    {
        $orderId = $this->createOrder(quantity: 1)->json('id');

        $this->actingAs($this->user)
            ->deleteJson("/api/orders/{$orderId}")
            ->assertStatus(200);

        $this->actingAs($this->user)
            ->deleteJson("/api/orders/{$orderId}")
            ->assertStatus(422);
    }

    public function test_cannot_delete_order_from_different_tenant(): void
    {
        $orderId = $this->createOrder(quantity: 1)->json('id');

        $otherTenant = Tenant::factory()->create();
        $otherUser   = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);

        $this->actingAs($otherUser)
            ->deleteJson("/api/orders/{$orderId}")
            ->assertStatus(403);
    }

    public function test_cannot_modify_order_after_payment(): void
    {
        $orderId = $this->createOrder(quantity: 2)->json('id');

        $this->createPayment($orderId, 200)->assertStatus(201);

        $this->actingAs($this->user)->patchJson("/api/orders/{$orderId}", [
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 3],
            ],
        ])->assertStatus(422);
    }

    // ─────────────────────────────────────────
    // LEDGER TESTS
    // ─────────────────────────────────────────
    public function test_order_charge_ledger_entry_created_on_order(): void
    {
        $this->createOrder(quantity: 2)->assertStatus(201);

        $this->assertDatabaseHas('ledger_entries', [
            'tenant_id'   => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'type'        => 'ORDER_CHARGE',
            'amount'      => 200.00,
        ]);
    }

    public function test_reversal_ledger_entry_created_on_order_delete(): void
    {
        $orderId = $this->createOrder(quantity: 2)->json('id');

        $this->actingAs($this->user)
            ->deleteJson("/api/orders/{$orderId}")
            ->assertStatus(200);

        $this->assertDatabaseHas('ledger_entries', [
            'tenant_id'   => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'type'        => 'REVERSAL',
            'amount'      => 200.00,
        ]);

        $this->assertSoftDeleted('orders', ['id' => $orderId]);
    }

    public function test_payment_ledger_entry_created_on_payment(): void
    {
        $orderId = $this->createOrder(quantity: 2)->json('id');

        $this->createPayment($orderId, 200)->assertStatus(201);

        $this->assertDatabaseHas('ledger_entries', [
            'type'        => 'PAYMENT',
            'amount'      => 200.00,
            'customer_id' => $this->customer->id,
        ]);
    }

    public function test_credit_apply_ledger_entry_created_on_overpayment(): void
    {
        $orderId = $this->createOrder(quantity: 2)->json('id'); // total = 200

        $this->createPayment($orderId, 250)->assertStatus(201); // overpay by 50

        $this->assertDatabaseHas('ledger_entries', [
            'type'        => 'CREDIT_APPLY',
            'amount'      => 50.00,
            'customer_id' => $this->customer->id,
        ]);
    }

    public function test_ledger_entries_cannot_be_modified(): void
    {
        $this->createOrder(quantity: 1);

        $entry = LedgerEntry::first();

        $this->actingAs($this->user)
            ->patchJson("/api/ledger/{$entry->id}", ['amount' => 999])
            ->assertStatus(404);
    }

    // ─────────────────────────────────────────
    // BALANCE TESTS
    // ─────────────────────────────────────────
    public function test_customer_balance_is_correct_after_order_and_payment(): void
    {
        $orderId = $this->createOrder(quantity: 3)->json('id'); // total = 300

        $this->createPayment($orderId, 200); // pay 200

        $this->actingAs($this->user)
            ->getJson("/api/customers/{$this->customer->id}/balance")
            ->assertStatus(200)
            ->assertJson(['balance' => 100.00]);
    }

    public function test_balance_correct_after_partial_payment(): void
    {
        $orderId = $this->createOrder(quantity: 3)->json('id'); // total = 300

        $this->createPayment($orderId, 100)->assertStatus(201);

        $this->actingAs($this->user)
            ->getJson("/api/customers/{$this->customer->id}/balance")
            ->assertStatus(200)
            ->assertJson(['balance' => 200.00]);
    }

    public function test_balance_correct_after_multiple_payments(): void
    {
        $orderId = $this->createOrder(quantity: 3)->json('id'); // total = 300

        $this->createPayment($orderId, 100)->assertStatus(201);
        $this->createPayment($orderId, 100)->assertStatus(201);
        $this->createPayment($orderId, 150)->assertStatus(201); // overpay by 50

        $this->actingAs($this->user)
            ->getJson("/api/customers/{$this->customer->id}/balance")
            ->assertStatus(200)
            ->assertJson(['balance' => -50.00]);

        $this->assertDatabaseHas('ledger_entries', [
            'type'        => 'CREDIT_APPLY',
            'amount'      => 50.00,
            'customer_id' => $this->customer->id,
        ]);
    }

    public function test_ledger_history_returns_entries_in_order(): void
    {
        $orderId = $this->createOrder(quantity: 2)->json('id');

        $this->createPayment($orderId, 200)->assertStatus(201);

        $response = $this->actingAs($this->user)
            ->getJson("/api/customers/{$this->customer->id}/ledger")
            ->assertStatus(200);

        $history = $response->json('history');

        $this->assertCount(2, $history);
        $this->assertEquals('ORDER_CHARGE', $history[0]['type']);
        $this->assertEquals(200.00, $history[0]['amount']);
        $this->assertEquals('PAYMENT', $history[1]['type']);
        $this->assertEquals(200.00, $history[1]['amount']);
    }

    public function test_manual_credit_reduces_balance(): void
    {
        $this->createOrder(quantity: 1); // owes 100

        $this->actingAs($this->user)
            ->postJson("/api/customers/{$this->customer->id}/credit", [
                'amount'      => 40.00,
                'description' => 'Advance payment',
            ])->assertStatus(201);

        $this->actingAs($this->user)
            ->getJson("/api/customers/{$this->customer->id}/balance")
            ->assertStatus(200)
            ->assertJson(['balance' => 60.00]);

        $this->assertDatabaseHas('ledger_entries', [
            'type'        => 'CREDIT_APPLY',
            'amount'      => 40.00,
            'customer_id' => $this->customer->id,
            'description' => 'Advance payment',
        ]);
    }

    public function test_cannot_get_balance_for_nonexistent_customer(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/customers/99999/balance")
            ->assertStatus(404);
    }

    public function test_cannot_get_history_for_nonexistent_customer(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/customers/99999/ledger")
            ->assertStatus(404);
    }

    // ─────────────────────────────────────────
    // PAYMENT VALIDATION TESTS
    // ─────────────────────────────────────────
    public function test_cannot_create_payment_with_zero_amount(): void
    {
        $orderId = $this->createOrder(quantity: 2)->json('id');

        $this->createPayment($orderId, 0)->assertStatus(422);

        $this->assertDatabaseMissing('ledger_entries', [
            'type'        => 'PAYMENT',
            'customer_id' => $this->customer->id,
        ]);
    }

    public function test_cannot_create_payment_with_negative_amount(): void
    {
        $orderId = $this->createOrder(quantity: 2)->json('id');

        $this->createPayment($orderId, -1)->assertStatus(422);

        $this->assertDatabaseMissing('ledger_entries', [
            'type'        => 'PAYMENT',
            'customer_id' => $this->customer->id,
        ]);
    }

    public function test_cannot_create_payment_with_invalid_order_id(): void
    {
        $this->actingAs($this->user)->postJson('/api/payments', [
            'order_id'    => 999,
            'customer_id' => $this->customer->id,
            'amount'      => 100,
            'method'      => 'cash',
        ])->assertStatus(422);
    }

    public function test_cannot_create_payment_with_invalid_method(): void
    {
        $orderId = $this->createOrder(quantity: 2)->json('id');

        $this->actingAs($this->user)->postJson('/api/payments', [
            'order_id'    => $orderId,
            'customer_id' => $this->customer->id,
            'amount'      => 100,
            'method'      => 'bitcoin',
        ])->assertStatus(422);
    }

    public function test_cannot_create_payment_with_mismatched_customer(): void
    {
        $orderId       = $this->createOrder(quantity: 1)->json('id');
        $otherCustomer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->user)->postJson('/api/payments', [
            'order_id'    => $orderId,
            'customer_id' => $otherCustomer->id,
            'amount'      => 100,
            'method'      => 'cash',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('ledger_entries', [
            'type'        => 'PAYMENT',
            'customer_id' => $otherCustomer->id,
        ]);
    }

    public function test_cannot_create_payment_on_cancelled_order(): void
    {
        $orderId = $this->createOrder(quantity: 1)->json('id');

        $this->actingAs($this->user)
            ->deleteJson("/api/orders/{$orderId}")
            ->assertStatus(200);

        $this->actingAs($this->user)->postJson('/api/payments', [
            'order_id'    => $orderId,
            'customer_id' => $this->customer->id,
            'amount'      => 100.00,
            'method'      => 'cash',
        ])->assertStatus(422);
    }

    public function test_cannot_pay_order_already_fully_paid_order(): void
    {
        $orderId = $this->createOrder(quantity: 1)->json('id');

        $this->createPayment($orderId, 100)->assertStatus(201);

        $this->createPayment($orderId, 100)
            ->assertStatus(422)
            ->assertJson(['message' => 'Order is already fully paid.']);
    }

    // ─────────────────────────────────────────
    // ORDER STATUS TESTS
    // ─────────────────────────────────────────
    public function test_order_status_is_unpaid_before_payment(): void
    {
        $response = $this->createOrder(quantity: 1)->assertStatus(201);

        $this->assertEquals('unpaid', $response->json('status'));
    }

    public function test_order_status_is_paid_after_full_payment(): void
    {
        $orderId = $this->createOrder(quantity: 1)->json('id');

        $this->createPayment($orderId, 100)->assertStatus(201);

        $this->actingAs($this->user)
            ->getJson("/api/orders/{$orderId}")
            ->assertStatus(200)
            ->assertJson(['status' => 'paid']);
    }

    public function test_order_status_is_unpaid_after_partial_payment(): void
    {
        $orderId = $this->createOrder(quantity: 1)->json('id');

        $this->createPayment($orderId, 50)->assertStatus(201);

        $this->actingAs($this->user)
            ->getJson("/api/orders/{$orderId}")
            ->assertStatus(200)
            ->assertJson(['status' => 'unpaid']);
    }

    // ─────────────────────────────────────────
    // CREDIT VALIDATION TESTS
    // ─────────────────────────────────────────
    public function test_cannot_add_credit_with_zero_amount(): void
    {
        $this->actingAs($this->user)
            ->postJson("/api/customers/{$this->customer->id}/credit", [
                'amount' => 0,
            ])->assertStatus(422);
    }

    public function test_cannot_add_credit_with_negative_amount(): void
    {
        $this->actingAs($this->user)
            ->postJson("/api/customers/{$this->customer->id}/credit", [
                'amount' => -100,
            ])->assertStatus(422);
    }

    // ─────────────────────────────────────────
    // PRODUCT TESTS
    // ─────────────────────────────────────────
    public function test_cannot_create_product_with_duplicate_sku_in_same_tenant(): void
    {
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sku'       => 'SAME-SKU',
        ]);

        $this->actingAs($this->user)->postJson('/api/products', [
            'name'  => 'Duplicate Product',
            'sku'   => 'SAME-SKU',
            'price' => 100.00,
        ])->assertStatus(422);
    }

    public function test_can_create_product_with_same_sku_in_different_tenant(): void
    {
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sku'       => 'SAME-SKU',
        ]);

        $otherTenant = Tenant::factory()->create();
        $otherUser   = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);

        $this->actingAs($otherUser)->postJson('/api/products', [
            'name'  => 'Another Product',
            'sku'   => 'SAME-SKU',
            'price' => 50,
            'unit'  => 'pcs',
        ])->assertStatus(201);
    }
}