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
}
