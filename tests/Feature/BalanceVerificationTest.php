<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceVerificationTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_balance_after_overpayment(): void
    {
        // 1. Create order for 100
        $this->postJson('/api/orders', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ])->assertStatus(201);

        $order = Order::first();

        // 2. Pay 150 (50 overpayment)
        $this->postJson('/api/payments', [
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'order_id'    => $order->id,
            'customer_id' => $this->customer->id,
            'amount'      => 150.00,
            'method'      => 'cash',
        ])->assertStatus(201);

        // 3. Check balance
        // Expectation: 100 - 150 = -50
        $response = $this->getJson("/api/customers/{$this->customer->id}/balance");
        $response->assertStatus(200);
        
        $balance = $response->json('balance');
        dump("Current Balance after overpayment: " . $balance);
        
        // Let's see if it's -50
        $this->assertEquals(-50.00, $balance, "Balance should be -50 after paying 150 for a 100 order.");
    }
}
