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

class DirectPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function setupOrder(float $itemPrice = 500, int $quantity = 1): array
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
        $order     = Order::factory()->create([
            'tenant_id'   => $tenant->id,
            'store_id'    => $store->id,
            'customer_id' => $customer->id,
        ]);
        OrderItem::factory()->create([
            'order_id'     => $order->id,
            'product_id'   => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity'     => $quantity,
            'unit_price'   => $itemPrice,
            'unit_type'    => 'base',
        ]);

        return compact('tenant', 'user', 'store', 'customer', 'order');
    }

    public function test_direct_payment_processes_successfully(): void
    {
        [
            'user'     => $user,
            'customer' => $customer,
            'order'    => $order,
        ] = $this->setupOrder(500);

        $response = $this->actingAs($user)->postJson('/api/payments', [
            'order_id'    => $order->id,
            'customer_id' => $customer->id,
            'amount'      => 500,
            'method'      => 'cash',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'Payment processed successfully.');
        $this->assertDatabaseHas('payments', [
            'order_id'    => $order->id,
            'customer_id' => $customer->id,
            'amount'      => 500,
            'method'      => 'cash',
        ]);
    }

    public function test_direct_partial_payment_creates_payment_record(): void
    {
        [
            'user'     => $user,
            'customer' => $customer,
            'order'    => $order,
        ] = $this->setupOrder(500);

        $response = $this->actingAs($user)->postJson('/api/payments', [
            'order_id'    => $order->id,
            'customer_id' => $customer->id,
            'amount'      => 200,
            'method'      => 'cash',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount'   => 200,
        ]);
        // ledger entry for 200 applied
        $this->assertDatabaseHas('ledger_entries', [
            'customer_id' => $customer->id,
            'type'        => 'PAYMENT',
            'amount'      => 200,
        ]);
    }

    public function test_direct_overpayment_creates_credit_entry(): void
    {
        [
            'user'     => $user,
            'customer' => $customer,
            'order'    => $order,
        ] = $this->setupOrder(300);

        // Pay 500 on a 300 order — 200 excess
        $response = $this->actingAs($user)->postJson('/api/payments', [
            'order_id'    => $order->id,
            'customer_id' => $customer->id,
            'amount'      => 500,
            'method'      => 'cash',
        ]);

        $response->assertStatus(201);
        // 300 applied as PAYMENT
        $this->assertDatabaseHas('ledger_entries', [
            'customer_id' => $customer->id,
            'type'        => 'PAYMENT',
            'amount'      => 300,
        ]);
        // 200 excess stored as CREDIT_APPLY
        $this->assertDatabaseHas('ledger_entries', [
            'customer_id' => $customer->id,
            'type'        => 'CREDIT_APPLY',
            'amount'      => 200,
        ]);
    }

    public function test_direct_payment_fails_if_order_already_paid(): void
    {
        [
            'tenant'   => $tenant,
            'user'     => $user,
            'customer' => $customer,
            'order'    => $order,
        ] = $this->setupOrder(500);

        // Pre-pay the order fully
        Payment::factory()->create([
            'tenant_id'   => $tenant->id,
            'order_id'    => $order->id,
            'customer_id' => $customer->id,
            'amount'      => 500,
        ]);

        $response = $this->actingAs($user)->postJson('/api/payments', [
            'order_id'    => $order->id,
            'customer_id' => $customer->id,
            'amount'      => 100,
            'method'      => 'cash',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Order is already fully paid.');
    }

    public function test_direct_payment_requires_authentication(): void
    {
        $response = $this->postJson('/api/payments', [
            'order_id'    => 1,
            'customer_id' => 1,
            'amount'      => 100,
            'method'      => 'cash',
        ]);

        $response->assertStatus(401);
    }

    public function test_direct_payment_fails_without_required_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role'      => 'tenant_admin',
            'store_id'  => null,
        ]);

        $response = $this->actingAs($user)->postJson('/api/payments', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['order_id', 'customer_id', 'amount', 'method']);
    }
}