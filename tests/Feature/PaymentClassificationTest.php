<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class PaymentClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Customer $customer;
    protected Product $product;
    protected User $user;
    protected Warehouse $shelf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price'     => 60,
        ]);
        $this->user = User::factory()->create([
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

    private function unpaidOrder(float $total): Order
    {
        return Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'total'       => $total,
        ]);
    }

    private function sell(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/orders', array_merge([
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => 1]],
        ], $overrides));
    }

    public function test_direct_payment_is_stored_as_cash(): void
    {
        $order = $this->unpaidOrder(100);

        $this->actingAs($this->user)->postJson('/api/payments', [
            'order_id'    => $order->id,
            'customer_id' => $this->customer->id,
            'amount'      => 100,
            'method'      => 'cash',
        ])->assertStatus(201);

        $this->assertDatabaseHas('payments', [
            'order_id'           => $order->id,
            'counts_as_cash'     => true,
            'is_auto_reversible' => false,
        ]);
    }

    public function test_direct_payment_excess_spread_to_older_orders_is_stored_as_cash(): void
    {
        $older = $this->unpaidOrder(40);
        $paid  = $this->unpaidOrder(100);

        $this->actingAs($this->user)->postJson('/api/payments', [
            'order_id'    => $paid->id,
            'customer_id' => $this->customer->id,
            'amount'      => 140,
            'method'      => 'cash',
        ])->assertStatus(201);

        $this->assertDatabaseHas('payments', [
            'order_id'           => $older->id,
            'amount'             => 40,
            'counts_as_cash'     => true,
            'is_auto_reversible' => false,
        ]);
    }

    public function test_auto_payment_is_stored_as_cash(): void
    {
        $order = $this->unpaidOrder(100);

        $this->actingAs($this->user)->postJson('/api/payments/auto', [
            'customer_id' => $this->customer->id,
            'amount'      => 100,
            'method'      => 'cash',
        ])->assertStatus(201);

        $this->assertDatabaseHas('payments', [
            'order_id'           => $order->id,
            'counts_as_cash'     => true,
            'is_auto_reversible' => false,
        ]);
    }

    public function test_sale_paid_from_credit_is_stored_as_non_cash_and_auto_reversible(): void
    {
        $this->actingAs($this->user)->postJson("/api/customers/{$this->customer->id}/credit", [
            'amount' => 100,
        ])->assertStatus(201);

        $orderId = $this->sell()->assertStatus(201)->json('id');

        $this->assertDatabaseHas('payments', [
            'order_id'           => $orderId,
            'amount'             => 60,
            'method'             => 'credit',
            'counts_as_cash'     => false,
            'is_auto_reversible' => true,
        ]);
    }

    public function test_sale_paid_immediately_is_stored_as_cash(): void
    {
        $orderId = $this->sell([
            'pay_immediately' => true,
            'payment_method'  => 'cash',
        ])->assertStatus(201)->json('id');

        $this->assertDatabaseHas('payments', [
            'order_id'           => $orderId,
            'amount'             => 60,
            'counts_as_cash'     => true,
            'is_auto_reversible' => false,
        ]);
    }

    public function test_payment_without_counts_as_cash_is_rejected(): void
    {
        $order = $this->unpaidOrder(100);

        try {
            Payment::create([
                'tenant_id'          => $this->tenant->id,
                'order_id'           => $order->id,
                'customer_id'        => $this->customer->id,
                'amount'             => 10,
                'method'             => 'cash',
                'is_auto_reversible' => false,
                'paid_at'            => now(),
            ]);
            $this->fail('A payment without counts_as_cash was saved.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('counts_as_cash', $e->getMessage());
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_without_is_auto_reversible_is_rejected(): void
    {
        $order = $this->unpaidOrder(100);

        try {
            Payment::create([
                'tenant_id'      => $this->tenant->id,
                'order_id'       => $order->id,
                'customer_id'    => $this->customer->id,
                'amount'         => 10,
                'method'         => 'cash',
                'counts_as_cash' => true,
                'paid_at'        => now(),
            ]);
            $this->fail('A payment without is_auto_reversible was saved.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('is_auto_reversible', $e->getMessage());
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_return_offset_is_an_accepted_payment_method(): void
    {
        $order = $this->unpaidOrder(100);

        $payment = Payment::factory()->returnOffset()->create([
            'tenant_id'   => $this->tenant->id,
            'order_id'    => $order->id,
            'customer_id' => $this->customer->id,
            'amount'      => 30,
            'paid_at'     => now(),
        ]);

        $this->assertDatabaseHas('payments', [
            'id'                 => $payment->id,
            'method'             => 'return_offset',
            'counts_as_cash'     => false,
            'is_auto_reversible' => false,
        ]);
    }

    private function offsetPayment(Order $order, float $amount): Payment
    {
        return Payment::factory()->returnOffset()->create([
            'tenant_id'   => $this->tenant->id,
            'order_id'    => $order->id,
            'customer_id' => $this->customer->id,
            'amount'      => $amount,
            'paid_at'     => now(),
        ]);
    }

    public function test_return_offset_settles_the_order_but_is_never_cash(): void
    {
        $order = $this->unpaidOrder(100);
        $this->offsetPayment($order, 100);

        $order->load('payments');
        $this->assertTrue($order->isSettled());
        $this->assertEquals(0, $order->cashReceived());
        $this->assertSame(0, Payment::cashOnly()->count());
        $this->assertSame(0, Payment::creditOnly()->count());

        $this->actingAs($this->user)->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('refundable_amount', 0)
            ->assertJsonCount(0, 'payments');

        $this->assertEquals(0, $this->actingAs($this->user)
            ->getJson('/api/dashboard?period=today')->json('stats.today_revenue'));
    }

    public function test_return_offset_cannot_be_refunded_as_cash_by_payment(): void
    {
        $order   = $this->unpaidOrder(100);
        $payment = $this->offsetPayment($order, 100);

        $this->actingAs($this->user)->postJson("/api/customers/{$this->customer->id}/refund", [
            'amount'            => 50,
            'method'            => 'cash',
            'payment_id_target' => $payment->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['payment_id_target']);

        $this->assertEquals(0, $payment->fresh()->refunded_amount ?? 0);
        $this->assertDatabaseMissing('ledger_entries', ['type' => 'REFUND']);
    }

    public function test_return_offset_cannot_be_refunded_as_cash_by_order(): void
    {
        $order = $this->unpaidOrder(100);
        $payment = $this->offsetPayment($order, 100);

        $this->actingAs($this->user)->postJson("/api/customers/{$this->customer->id}/refund", [
            'amount'   => 50,
            'method'   => 'cash',
            'order_id' => $order->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->assertEquals(0, $payment->fresh()->refunded_amount ?? 0);
        $this->assertDatabaseMissing('ledger_entries', ['type' => 'REFUND']);
    }

    public function test_refund_service_rejects_a_non_cash_payment_even_without_the_validator(): void
    {
        $order   = $this->unpaidOrder(100);
        $payment = $this->offsetPayment($order, 100);

        try {
            app(LedgerService::class)->issueRefund([
                'tenant_id'         => $this->tenant->id,
                'customer_id'       => $this->customer->id,
                'store_id'          => $this->store->id,
                'amount'            => 50,
                'method'            => 'cash',
                'payment_id_target' => $payment->id,
            ]);
            $this->fail('A non-cash payment was refunded as cash.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        $this->assertEquals(0, $payment->fresh()->refunded_amount ?? 0);
        $this->assertDatabaseMissing('ledger_entries', ['type' => 'REFUND']);
    }

    public function test_customer_summary_exposes_counts_as_cash(): void
    {
        $order = $this->unpaidOrder(100);
        $this->offsetPayment($order, 30);

        $payments = $this->actingAs($this->user)
            ->getJson("/api/customers/{$this->customer->id}/summary")
            ->assertOk()
            ->json('payments');

        $this->assertFalse($payments[0]['counts_as_cash']);
        $this->assertFalse($payments[0]['is_auto_reversible']);
    }
}
