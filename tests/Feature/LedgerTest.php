<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────
    // Shared setup — runs before each test
    // ─────────────────────────────────────────
    protected Tenant $tenant;
    protected Store $store;
    protected Customer $customer;
    protected Product $product;

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
    }

    // ─────────────────────────────────────────
    // Helper — creates an order via API
    // ─────────────────────────────────────────
    private function createOrder(int $quantity = 2): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/orders', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'notes'       => 'Test order',
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => $quantity],
            ],
        ]);
    }
    public function test_cannot_create_order_with_no_items(): void
    {
        $this->postJson('/api/orders', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'notes'       => 'Test order',
        ])->assertStatus(422);
    }
    public function test_cannot_create_order_with_empty_items_array(): void
    {
        $this->postJson('/api/orders', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'notes'       => 'Test order',
            'items'       => [],
        ])->assertStatus(422);
    }
    public function test_cannot_create_order_with_invalid_tenant_id(int $quantity = 2): void
    {
        $this->postJson('/api/orders', [
            'tenant_id'   => 999,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'notes'       => 'Test order',
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => $quantity],
            ],
        ])->assertStatus(422);
    }
    public function test_cannot_create_order_with_invalid_store_id(int $quantity = 2): void
    {
        $this->postJson('/api/orders', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => 999,
            'customer_id' => $this->customer->id,
            'notes'       => 'Test order',
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => $quantity],
            ],
        ])->assertStatus(422);
    }
    public function test_cannot_create_order_with_invalid_customer_id(int $quantity = 2): void
    {
        $this->postJson('/api/orders', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => 999,
            'notes'       => 'Test order',
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => $quantity],
            ],
        ])->assertStatus(422);
    }
    public function test_cannot_create_order_with_invalid_product_id(int $quantity = 2): void
    {
        $this->postJson('/api/orders', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'notes'       => 'Test order',
            'items'       => [
                ['product_id' => 999, 'quantity' => $quantity],
            ],
        ])->assertStatus(422);
    }
    public function test_cannot_create_order_with_zero_quantity(int $quantity = 0): void
    {
        $this->postJson('/api/orders', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'notes'       => 'Test order',
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => $quantity]
            ],
        ])->assertStatus(422);
    }
    public function test_cannot_create_order_with_negative_quantity(int $quantity = -1): void
    {
        $this->postJson('/api/orders', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'notes'       => 'Test order',
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => $quantity]
            ],
        ])->assertStatus(422);
    }
    public function test_cannot_create_order_with_customer_from_different_tenant(int $quantity = 1): void
    {
         $otherTenant   = Tenant::factory()->create();
    $otherCustomer = Customer::factory()->create([
        'tenant_id' => $otherTenant->id
    ]);
    
        $this->postJson('/api/orders', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $otherCustomer->id,
            'notes'       => 'Test order',
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => $quantity]
            ],
        ])->assertStatus(422);
    }
    public function test_cannot_create_order_with_store_from_different_tenant(int $quantity = 1): void
    {
         $otherTenant   = Tenant::factory()->create();
    $otherStore = Store::factory()->create([
        'tenant_id' => $otherTenant->id
    ]);
    
        $this->postJson('/api/orders', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $otherStore->id,
            'customer_id' => $this->customer->id,
            'notes'       => 'Test order',
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => $quantity]
            ],
        ])->assertStatus(422);
    }
    public function test_cannot_delete_already_deleted_order(): void
{
    // 1. Create order
    $response = $this->createOrder(quantity: 1);
    $orderId  = $response->json('id');

    // 2. Delete order (first delete works)
    $this->deleteJson("/api/orders/{$orderId}", [
        'tenant_id' => $this->tenant->id
    ])->assertStatus(200);

    // 3. Try deleting again
    $this->deleteJson("/api/orders/{$orderId}", [
        'tenant_id' => $this->tenant->id
    ])->assertStatus(422); 
}
public function test_cannot_delete_order_from_different_tenant(): void
{
    // Tenant A creates an order
    $response = $this->createOrder(quantity: 1);
    $orderId  = $response->json('id');

    // Create Tenant B
    $otherTenant = Tenant::factory()->create();
    $otherStore  = Store::factory()->create([
        'tenant_id' => $otherTenant->id
    ]);

    // Tenant B attempts to delete Tenant A's order
    $this->deleteJson("/api/orders/{$orderId}", [
        'tenant_id' => $otherTenant->id,
        'store_id'  => $otherStore->id
    ])->assertStatus(403);
}

    

    // ─────────────────────────────────────────
    // TEST 1 — ORDER_CHARGE on order create
    // ─────────────────────────────────────────
    public function test_order_charge_ledger_entry_created_on_order(): void
    {
        $response = $this->createOrder(quantity: 2);

        $response->assertStatus(201);

        $this->assertDatabaseHas('ledger_entries', [
            'tenant_id'   => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'type'        => 'ORDER_CHARGE',
            'amount'      => 200.00, // 2 x 100
        ]);
    }

    // ─────────────────────────────────────────
    // TEST 2 — REVERSAL on order delete
    // ─────────────────────────────────────────
    public function test_reversal_ledger_entry_created_on_order_delete(): void
    {
        $response = $this->createOrder(quantity: 2);
        $orderId  = $response->json('id');

        $this->deleteJson("/api/orders/{$orderId}", [
            'tenant_id' => $this->tenant->id
        ])->assertStatus(200);

        $this->assertDatabaseHas('ledger_entries', [
            'tenant_id'   => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'type'        => 'REVERSAL',
            'amount'      => 200.00,
        ]);

        // Order must be soft deleted — not hard deleted
        $this->assertSoftDeleted('orders', ['id' => $orderId]);
    }

    // ─────────────────────────────────────────
    // TEST 3 — PAYMENT credit on payment create
    // ─────────────────────────────────────────
    public function test_payment_ledger_entry_created_on_payment(): void
    {
        $response = $this->createOrder(quantity: 2);
        $order    = $response->json();

        $this->postJson('/api/payments', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'order_id'    => $order['id'],
            'customer_id' => $this->customer->id,
            'amount'      => 200.00,
            'method'      => 'cash',
        ])->assertStatus(201);

        $this->assertDatabaseHas('ledger_entries', [
            'type'        => 'PAYMENT',
            'amount'      => 200.00,
            'customer_id' => $this->customer->id,
        ]);
    }
   public function test_cannot_create_payment_with_zero_amount(): void
{
    $response = $this->createOrder(quantity: 2);
    $order    = $response->json();

    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $order['id'],
        'customer_id' => $this->customer->id,
        'amount'      => 0, // ← invalid
        'method'      => 'cash',
    ])->assertStatus(422); // ← must be rejected

    // No ledger entry should exist
    $this->assertDatabaseMissing('ledger_entries', [
        'type'        => 'PAYMENT',
        'customer_id' => $this->customer->id,
    ]);
}
   public function test_cannot_create_payment_with_negative_amount(): void
{
    $response = $this->createOrder(quantity: 2);
    $order    = $response->json();

    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $order['id'],
        'customer_id' => $this->customer->id,
        'amount'      => -1, 
        'method'      => 'cash',
    ])->assertStatus(422); 

    // No ledger entry should exist
    $this->assertDatabaseMissing('ledger_entries', [
        'type'        => 'PAYMENT',
        'customer_id' => $this->customer->id,
    ]);
}
   public function test_cannot_create_payment_with_invalid_order_id(): void
{
    

    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => 999,
        'customer_id' => $this->customer->id,
        'amount'      => 100, 
        'method'      => 'cash',
    ])->assertStatus(422); 
}
   public function test_cannot_create_payment_with_invalid_method(): void
{
    $response = $this->createOrder(quantity: 2);
    $order    = $response->json();

    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $order['id'],
        'customer_id' => $this->customer->id,
        'amount'      => 100, 
        'method'      => 'bitcoin',
    ])->assertStatus(422); 
}
   public function test_cannot_create_payment_with_mismatched_customer(): void
{
     $response = $this->createOrder(quantity: 1);
    $order    = $response->json();

    $otherCustomer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $order['id'],
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
    $response = $this->createOrder(quantity: 1);
    $orderId  = $response->json('id');

    // Cancel the order
    $this->deleteJson("/api/orders/{$orderId}", [
        'tenant_id' => $this->tenant->id
    ])->assertStatus(200);

    // Try to pay cancelled order
    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $orderId,
        'customer_id' => $this->customer->id,
        'amount'      => 100.00,
        'method'      => 'cash',
    ])->assertStatus(422);
}

    // ─────────────────────────────────────────
    // TEST 4 — CREDIT_APPLY on overpayment
    // ─────────────────────────────────────────
    public function test_credit_apply_ledger_entry_created_on_overpayment(): void
    {
        $response = $this->createOrder(quantity: 2); // total = 200
        $order    = $response->json();

        $this->postJson('/api/payments', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'order_id'    => $order['id'],
            'customer_id' => $this->customer->id,
            'amount'      => 250.00, // overpay by 50
            'method'      => 'cash',
        ])->assertStatus(201);

        $this->assertDatabaseHas('ledger_entries', [
            'type'        => 'CREDIT_APPLY',
            'amount'      => 50.00, // excess
            'customer_id' => $this->customer->id,
        ]);
    }

    // ─────────────────────────────────────────
    // TEST 5 — Balance calculation
    // ─────────────────────────────────────────
    public function test_customer_balance_is_correct_after_order_and_payment(): void
    {
        // Order total = 300 (3 x 100)
        $response = $this->createOrder(quantity: 3);
        $order    = $response->json();

        // Pay 200 — partial payment
        $this->postJson('/api/payments', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'order_id'    => $order['id'],
            'customer_id' => $this->customer->id,
            'amount'      => 200.00,
            'method'      => 'cash',
        ]);

        $this->getJson("/api/customers/{$this->customer->id}/balance")
            ->assertStatus(200)
            ->assertJson([
                'balance' => 100.00, // 300 - 200 = still owes 100
            ]);
    }

    public function test_balance_correct_after_partial_payment(): void
{
    // Order = 300 (3 x 100)
    $this->postJson('/api/orders', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'customer_id' => $this->customer->id,
        'items'       => [
            ['product_id' => $this->product->id, 'quantity' => 3],
        ],
    ])->assertStatus(201);

    $order = Order::first();

    // Pay only 100 — partial
    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $order->id,
        'customer_id' => $this->customer->id,
        'amount'      => 100.00,
        'method'      => 'cash',
    ])->assertStatus(201);

    // Balance should be 200 — still owes
    $this->getJson("/api/customers/{$this->customer->id}/balance")
        ->assertStatus(200)
        ->assertJson(['balance' => 200.00]);
}

public function test_balance_correct_after_multiple_payments(): void
{
    // Order = 300 (3 x 100)
    $this->postJson('/api/orders', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'customer_id' => $this->customer->id,
        'items'       => [
            ['product_id' => $this->product->id, 'quantity' => 3],
        ],
    ])->assertStatus(201);

    $order = Order::first();

    // Payment 1 — 100
    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $order->id,
        'customer_id' => $this->customer->id,
        'amount'      => 100.00,
        'method'      => 'cash',
    ])->assertStatus(201);

    // Payment 2 — 100
    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $order->id,
        'customer_id' => $this->customer->id,
        'amount'      => 100.00,
        'method'      => 'cash',
    ])->assertStatus(201);

    // Payment 3 — 150 (overpayment of 50)
    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $order->id,
        'customer_id' => $this->customer->id,
        'amount'      => 150.00,
        'method'      => 'cash',
    ])->assertStatus(201);

    // Total paid = 350, order = 300
    // Balance should be -50 (customer has credit)
    $this->getJson("/api/customers/{$this->customer->id}/balance")
        ->assertStatus(200)
        ->assertJson(['balance' => -50.00]);

    // CREDIT_APPLY should exist with amount 50
    $this->assertDatabaseHas('ledger_entries', [
        'type'        => 'CREDIT_APPLY',
        'amount'      => 50.00,
        'customer_id' => $this->customer->id,
    ]);
}

public function test_ledger_history_returns_entries_in_order(): void
{
    // Create order
    $this->postJson('/api/orders', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'customer_id' => $this->customer->id,
        'items'       => [
            ['product_id' => $this->product->id, 'quantity' => 2],
        ],
    ])->assertStatus(201);

    $order = Order::first();

    // Create payment
    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $order->id,
        'customer_id' => $this->customer->id,
        'amount'      => 200.00,
        'method'      => 'cash',
    ])->assertStatus(201);

    // Check history
    $response = $this->getJson("/api/customers/{$this->customer->id}/ledger");

    $response->assertStatus(200);

    $history = $response->json('history');

    // Should have 2 entries — ORDER_CHARGE and PAYMENT
    $this->assertCount(2, $history);

    // First entry should be ORDER_CHARGE
    $this->assertEquals('ORDER_CHARGE', $history[0]['type']);
    $this->assertEquals(200.00, $history[0]['amount']);

    // Second entry should be PAYMENT
    $this->assertEquals('PAYMENT', $history[1]['type']);
    $this->assertEquals(200.00, $history[1]['amount']);
}

public function test_manual_credit_reduces_balance(): void
{
    // Create order — customer owes 100
    $this->postJson('/api/orders', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'customer_id' => $this->customer->id,
        'items'       => [
            ['product_id' => $this->product->id, 'quantity' => 1],
        ],
    ])->assertStatus(201);

    // Add manual credit of 40
    $this->postJson("/api/customers/{$this->customer->id}/credit", [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'amount'      => 40.00,
        'description' => 'Advance payment',
    ])->assertStatus(201);

    // Balance should be 60 — still owes 60
    $this->getJson("/api/customers/{$this->customer->id}/balance")
        ->assertStatus(200)
        ->assertJson(['balance' => 60.00]);

    // Ledger should have CREDIT_APPLY entry
    $this->assertDatabaseHas('ledger_entries', [
        'type'        => 'CREDIT_APPLY',
        'amount'      => 40.00,
        'customer_id' => $this->customer->id,
        'description' => 'Advance payment',
    ]);
}

public function test_order_status_is_unpaid_before_payment(): void
{
    $response = $this->postJson('/api/orders', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'customer_id' => $this->customer->id,
        'items'       => [
            ['product_id' => $this->product->id, 'quantity' => 1],
        ],
    ])->assertStatus(201);

    $this->assertEquals('unpaid', $response->json('status'));
}

public function test_order_status_is_paid_after_full_payment(): void
{
    $response = $this->postJson('/api/orders', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'customer_id' => $this->customer->id,
        'items'       => [
            ['product_id' => $this->product->id, 'quantity' => 1],
        ],
    ])->assertStatus(201);

    $order = Order::first();

    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $order->id,
        'customer_id' => $this->customer->id,
        'amount'      => 100.00,
        'method'      => 'cash',
    ])->assertStatus(201);

    // Re-fetch order to get updated status
    $response = $this->getJson("/api/orders/{$order->id}?tenant_id={$this->tenant->id}");

    $response->assertStatus(200);
    $this->assertEquals('paid', $response->json('status'));
}
public function test_order_status_is_unpaid_after_partial_payment(): void
{
    $response = $this->postJson('/api/orders', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'customer_id' => $this->customer->id,
        'items'       => [
            ['product_id' => $this->product->id, 'quantity' => 1],
        ],
    ])->assertStatus(201);

    $order = Order::first();

    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $order->id,
        'customer_id' => $this->customer->id,
        'amount'      => 50.00,
        'method'      => 'cash',
    ])->assertStatus(201);

    // Re-fetch order to get updated status
    $response = $this->getJson("/api/orders/{$order->id}?tenant_id={$this->tenant->id}");

    $response->assertStatus(200);
    $this->assertEquals('unpaid', $response->json('status'));
}
public function test_cannot_add_credit_with_zero_amount(): void
{
   
   $this->postJson("/api/customers/{$this->customer->id}/credit", [
        'tenant_id' => $this->tenant->id,
        'store_id'  => $this->store->id,
        'amount'    => 0,
    ])->assertStatus(422);
}
public function test_cannot_add_credit_with_negative_amount(): void
{
   
   $this->postJson("/api/customers/{$this->customer->id}/credit", [
        'tenant_id' => $this->tenant->id,
        'store_id'  => $this->store->id,
        'amount'    => -100,
    ])->assertStatus(422);
}
public function test_cannot_add_credit_with_invalid_tenant_id(): void
{
    $this->postJson("/api/customers/{$this->customer->id}/credit", [
        'tenant_id' => 999,
        'store_id'  => $this->store->id,
        'amount'    => 100.00,
    ])->assertStatus(422);
}
public function test_cannot_get_balance_for_nonexistent_customer(): void
{
    $this->getJson("/api/customers/99999/balance")
        ->assertStatus(404);
}
public function test_cannot_get_history_for_nonexistent_customer(): void
{
    $this->getJson("/api/customers/99999/ledger")
        ->assertStatus(404);
}
public function test_cannot_create_product_with_duplicate_sku_in_same_tenant(): void
{
    Product::factory()->create([
        'tenant_id' => $this->tenant->id,
        'sku'       => 'SAME-SKU',
    ]);
    
    $this->postJson('/api/products', [
        'tenant_id' => $this->tenant->id,
        'store_id'  => $this->store->id,
        'name'      => 'Duplicate Product',
        'sku'       => 'SAME-SKU',
        'price'     => 100.00,
    ])->assertStatus(422);
}
public function test_can_create_product_with_same_sku_in_different_tenant(): void
{
    Product::factory()->create([
        'tenant_id' => $this->tenant->id,
        'sku'       => 'SAME-SKU',
    ]);

    $otherTenant = Tenant::factory()->create();

    $this->postJson('/api/products', [
        'tenant_id' => $otherTenant->id,
        'name'      => 'Another Product',
        'sku'       => 'SAME-SKU', // ← same SKU, different tenant = allowed
        'price'     => 50,
        'unit'      => 'pcs',
    ])->assertStatus(201);
}
public function test_cannot_create_order_with_product_from_different_tenant(): void
{
    $otherTenant = Tenant::factory()->create();
    $otherProduct = Product::factory()->create([
        'tenant_id' => $otherTenant->id,
        'sku'       => 'OTHER-SKU',
    ]);
    $this->postJson('/api/orders', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'customer_id' => $this->customer->id,
        'items'       => [
            ['product_id' => $otherProduct->id, 'quantity' => 1],
        ],
    ])->assertStatus(422);
}

public function test_cannot_modify_order_after_payment(): void
{
    $response = $this->createOrder(quantity: 2);
    $orderId = $response->json('id');

    // Pay the order
    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $orderId,
        'customer_id' => $this->customer->id,
        'amount'      => 200,
        'method'      => 'cash',
    ])->assertStatus(201);

    // Attempt to modify order
    $this->patchJson("/api/orders/{$orderId}", [
        'tenant_id'   => $this->tenant->id,
        'items' => [
            ['product_id' => $this->product->id, 'quantity' => 3],
        ]
    ])->assertStatus(422);
}
public function test_cannot_pay_order_already_fully_paid_order(): void
{
    $response = $this->createOrder(quantity: 1);
    $orderId = $response->json('id');

    // Pay the order
    $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $orderId,
        'customer_id' => $this->customer->id,
        'amount'      => 100,
        'method'      => 'cash',
    ])->assertStatus(201);

   $this->postJson('/api/payments', [
        'tenant_id'   => $this->tenant->id,
        'store_id'    => $this->store->id,
        'order_id'    => $orderId,
        'customer_id' => $this->customer->id,
        'amount'      => 100,
        'method'      => 'cash',
    ])->assertStatus(422)
        ->assertJson(['message' => 'Order is already fully paid.']);
}

public function test_ledger_entries_cannot_be_modified(): void
{
    $this->createOrder(quantity: 1);

    $entry = LedgerEntry::first();

    $this->patchJson("/api/ledger/{$entry->id}", [
        'amount' => 999
    ])->assertStatus(404);
}

}