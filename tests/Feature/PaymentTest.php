<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;

class PaymentTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;
    
   public function test_payments_filtered_by_date_returns_correct_results(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => 'tenant_admin',
        'store_id' => null,
    ]);
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
$store = Store::factory()->create(['tenant_id' => $tenant->id]);
$order = Order::factory()->create([
    'tenant_id'   => $tenant->id,
    'store_id'    => $store->id,
    'customer_id' => $customer->id,
]);    // Payment today
    $todayPayment = Payment::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_id' => $customer->id,
        'order_id' => $order->id,
        'amount' => 500,
        'created_at' => now(),
    ]);

    // Payment yesterday
    $yesterdayPayment = Payment::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_id' => $customer->id,
        'order_id' => $order->id,
        'amount' => 200,
        'created_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/payments?date=' . now()->toDateString());

    $response->assertOk();
    $data = $response->json('data');
    $this->assertCount(1, $data);
    $this->assertEquals(500, $response->json('total'));
}

    private function paymentsListContext(): array
    {
        $tenant   = Tenant::factory()->create();
        $user     = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role'      => 'tenant_admin',
            'store_id'  => null,
        ]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        $store    = Store::factory()->create(['tenant_id' => $tenant->id]);
        $order    = Order::factory()->create([
            'tenant_id'   => $tenant->id,
            'store_id'    => $store->id,
            'customer_id' => $customer->id,
        ]);

        return [$user, [
            'tenant_id'   => $tenant->id,
            'customer_id' => $customer->id,
            'order_id'    => $order->id,
        ]];
    }

    public function test_payments_list_total_is_net_cash_and_gross_total_is_kept(): void
    {
        [$user, $base] = $this->paymentsListContext();

        Payment::factory()->create($base + ['amount' => 500, 'refunded_amount' => 100]);
        Payment::factory()->create($base + ['amount' => 200]);

        $response = $this->actingAs($user)->getJson('/api/payments')->assertOk();

        $this->assertEquals(600, $response->json('total'));
        $this->assertEquals(700, $response->json('gross_total'));
        $this->assertEquals(2, $response->json('count'));
    }

    public function test_payments_list_excludes_credit_and_return_offset_payments(): void
    {
        [$user, $base] = $this->paymentsListContext();

        Payment::factory()->create($base + ['amount' => 300]);
        Payment::factory()->credit()->create($base + ['amount' => 50]);
        Payment::factory()->returnOffset()->create($base + ['amount' => 70]);

        $response = $this->actingAs($user)->getJson('/api/payments')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(300, $response->json('total'));
        $this->assertEquals(300, $response->json('gross_total'));
    }
}
