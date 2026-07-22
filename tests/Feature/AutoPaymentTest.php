<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;

class AutoPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function setupCustomer(): array
    {
        $tenant    = Tenant::factory()->create();
        $user      = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role'      => 'tenant_admin',
            'store_id'  => null,
        ]);
        $store     = Store::factory()->create(['tenant_id' => $tenant->id]);
        $warehouse = Warehouse::factory()->create([
            'tenant_id' => $tenant->id,
            'store_id'  => $store->id,
        ]);
        $customer  = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $product   = Product::factory()->create(['tenant_id' => $tenant->id]);

        return compact('tenant', 'user', 'store', 'customer', 'warehouse', 'product');
    }

    private function createUnpaidOrder(array $ctx, float $total, \DateTimeInterface $createdAt): Order
    {
        $order = Order::factory()->create([
            'tenant_id'   => $ctx['tenant']->id,
            'store_id'    => $ctx['store']->id,
            'customer_id' => $ctx['customer']->id,
            'total'       => $total,
            'created_at'  => $createdAt,
        ]);
        OrderItem::factory()->create([
            'order_id'     => $order->id,
            'product_id'   => $ctx['product']->id,
            'warehouse_id' => $ctx['warehouse']->id,
            'quantity'     => 1,
            'unit_price'   => $total,
            'unit_type'    => 'base',
        ]);

        return $order;
    }

    public function test_auto_payment_settles_a_single_unpaid_order(): void
    {
        $ctx   = $this->setupCustomer();
        $order = $this->createUnpaidOrder($ctx, 300, now()->subDay());

        $response = $this->actingAs($ctx['user'])->postJson('/api/payments/auto', [
            'customer_id' => $ctx['customer']->id,
            'amount'      => 300,
            'method'      => 'cash',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount'   => 300,
        ]);
    }

    public function test_auto_payment_distributes_fifo_across_oldest_unpaid_orders_first(): void
    {
        $ctx = $this->setupCustomer();

        $older = $this->createUnpaidOrder($ctx, 200, now()->subDays(2));
        $newer = $this->createUnpaidOrder($ctx, 300, now()->subDay());

        // 250 covers the older order fully (200) and partially pays the newer (50).
        $response = $this->actingAs($ctx['user'])->postJson('/api/payments/auto', [
            'customer_id' => $ctx['customer']->id,
            'amount'      => 250,
            'method'      => 'cash',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('payments', ['order_id' => $older->id, 'amount' => 200]);
        $this->assertDatabaseHas('payments', ['order_id' => $newer->id, 'amount' => 50]);
    }

    public function test_auto_payment_excess_over_all_unpaid_orders_becomes_credit(): void
    {
        $ctx   = $this->setupCustomer();
        $order = $this->createUnpaidOrder($ctx, 200, now()->subDay());

        $response = $this->actingAs($ctx['user'])->postJson('/api/payments/auto', [
            'customer_id' => $ctx['customer']->id,
            'amount'      => 350,
            'method'      => 'cash',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'amount' => 200]);
        $this->assertDatabaseHas('ledger_entries', [
            'customer_id' => $ctx['customer']->id,
            'type'        => 'CREDIT_APPLY',
            'amount'      => 150,
        ]);
    }

    public function test_auto_payment_never_allocates_more_than_an_order_still_owes(): void
    {
        $ctx   = $this->setupCustomer();
        $order = $this->createUnpaidOrder($ctx, 300, now()->subDay());

        // Order already has a 100 payment on it — only 200 is actually owed.
        Payment::factory()->create([
            'tenant_id'   => $ctx['tenant']->id,
            'order_id'    => $order->id,
            'customer_id' => $ctx['customer']->id,
            'amount'      => 100,
        ]);

        $this->actingAs($ctx['user'])->postJson('/api/payments/auto', [
            'customer_id' => $ctx['customer']->id,
            'amount'      => 300,
            'method'      => 'cash',
        ])->assertStatus(201);

        // 100 pre-existing + at most 200 new = 300 total, never more than the order's total.
        $totalPaid = Payment::where('order_id', $order->id)->sum('amount');
        $this->assertEquals(300, $totalPaid);
    }
}
