<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderMoneyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Customer $customer;
    protected Product $product;
    protected User $user;
    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'price'     => 300,
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
        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => 100,
        ]);
    }

    private function createOrder(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/orders', array_merge([
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => now()->toDateString(),
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'warehouse_id' => $this->warehouse->id],
            ],
        ], $overrides));
    }

    // ─────────────────────────────────────────
    // 1. Invoice number generator
    // ─────────────────────────────────────────
    public function test_invoice_number_rolls_over_past_999_without_collision(): void
    {
        Order::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'store_id'       => $this->store->id,
            'customer_id'    => $this->customer->id,
            'invoice_number' => now()->year . '-999',
            'total'          => 100,
        ]);

        $response = $this->createOrder()->assertStatus(201);

        $this->assertEquals(now()->year . '-1000', $response->json('invoice_number'));
    }

    // ─────────────────────────────────────────
    // 2. manual_total
    // ─────────────────────────────────────────
    public function test_manual_total_overrides_computed_total_on_creation(): void
    {
        // items sum to 600 (2 x 300), manual_total overrides to 500
        $response = $this->createOrder(['manual_total' => 500])->assertStatus(201);

        $this->assertEquals(500, $response->json('total'));

        $orderId = $response->json('id');

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'total' => 500]);
        $this->assertDatabaseHas('ledger_entries', [
            'reference_type' => 'order',
            'reference_id'   => $orderId,
            'type'           => 'ORDER_CHARGE',
            'amount'         => 500,
        ]);
    }

    public function test_manual_total_does_not_survive_a_later_item_edit(): void
    {
        // Documented behavior: manual_total only applies at creation; edits recompute from items.
        $response = $this->createOrder(['manual_total' => 500])->assertStatus(201);

        $order = Order::find($response->json('id'));
        $item  = $order->items()->first();

        $this->actingAs($this->user)
            ->patchJson("/api/orders/{$order->id}/items/{$item->id}", ['quantity' => 3])
            ->assertStatus(200);

        // 3 x 300 = 900, no discount — the manual override is gone, matches financial-calculations.md
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total' => 900]);
    }

    // ─────────────────────────────────────────
    // 3. PaymentService reads orders.total, not a recomputed items sum
    // ─────────────────────────────────────────
    public function test_direct_payment_settles_a_manual_total_order_at_the_override_amount(): void
    {
        $response = $this->createOrder(['manual_total' => 500])->assertStatus(201);
        $orderId  = $response->json('id');

        $this->actingAs($this->user)->postJson('/api/payments', [
            'order_id'    => $orderId,
            'customer_id' => $this->customer->id,
            'amount'      => 500,
            'method'      => 'cash',
        ])->assertStatus(201);

        // Paying exactly the manual total should fully settle the order with no excess/credit,
        // even though the raw items sum (600) is higher.
        $this->assertDatabaseMissing('ledger_entries', [
            'reference_type' => 'payment',
            'type'            => 'CREDIT_APPLY',
        ]);

        $order = Order::find($orderId);
        $this->assertTrue($order->isSettled());
    }

    // ─────────────────────────────────────────
    // 4. Reports read orders.total, not a recomputed items sum
    // ─────────────────────────────────────────
    public function test_daily_report_revenue_respects_manual_total(): void
    {
        $this->createOrder(['manual_total' => 500])->assertStatus(201); // items sum to 600

        $response = $this->actingAs($this->user)
            ->getJson('/api/reports/daily?from=' . now()->toDateString() . '&to=' . now()->toDateString())
            ->assertStatus(200);

        $this->assertEquals(500, $response->json('summary.total_revenue'));
    }

    // ─────────────────────────────────────────
    // 5. Refund validator matches LedgerService::issueRefund
    // ─────────────────────────────────────────
    public function test_refund_validator_rejects_amount_exceeding_a_specific_payments_refundable_balance(): void
    {
        $response = $this->createOrder()->assertStatus(201); // total 600
        $orderId  = $response->json('id');

        $payment = Payment::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'order_id'    => $orderId,
            'customer_id' => $this->customer->id,
            'amount'      => 100,
            'method'      => 'cash',
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/customers/{$this->customer->id}/refund", [
                'amount'             => 150,
                'method'             => 'cash',
                'payment_id_target'  => $payment->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'refunded_amount' => 0]);
    }

    public function test_refund_validator_excludes_credit_payments_from_the_order_level_cap(): void
    {
        // Give the customer enough credit to fully cover the order via a credit (is_auto_reversible) payment.
        $this->actingAs($this->user)->postJson("/api/customers/{$this->customer->id}/credit", [
            'amount' => 300,
        ])->assertStatus(201);

        $response = $this->createOrder([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201); // total 300, fully covered by credit — no cash payments exist
        $orderId = $response->json('id');

        $this->assertDatabaseHas('payments', [
            'order_id'          => $orderId,
            'method'            => 'credit',
            'is_auto_reversible' => true,
        ]);

        // Old behavior: the validator summed ALL payments (including the credit one) and would have
        // let this refund request pass validation before failing inconsistently at the service layer.
        $this->actingAs($this->user)
            ->postJson("/api/customers/{$this->customer->id}/refund", [
                'amount'   => 300,
                'method'   => 'cash',
                'order_id' => $orderId,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_order_level_refund_distributes_across_multiple_payments_fifo(): void
    {
        $response = $this->createOrder()->assertStatus(201); // total 600
        $orderId  = $response->json('id');

        $paymentA = Payment::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'order_id'    => $orderId,
            'customer_id' => $this->customer->id,
            'amount'      => 200,
            'method'      => 'cash',
        ]);
        $paymentB = Payment::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'order_id'    => $orderId,
            'customer_id' => $this->customer->id,
            'amount'      => 200,
            'method'      => 'cash',
        ]);

        // Refund 250: fully consumes paymentA (200) then 50 from paymentB — exercises the
        // locked-collection FIFO path in LedgerService::issueRefund's order-level case.
        $this->actingAs($this->user)
            ->postJson("/api/customers/{$this->customer->id}/refund", [
                'amount'   => 250,
                'method'   => 'cash',
                'order_id' => $orderId,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('payments', ['id' => $paymentA->id, 'refunded_amount' => 200]);
        $this->assertDatabaseHas('payments', ['id' => $paymentB->id, 'refunded_amount' => 50]);
        $this->assertDatabaseHas('ledger_entries', [
            'reference_type' => 'order',
            'reference_id'   => $orderId,
            'type'           => 'REFUND',
            'amount'         => 250,
        ]);
    }
}
