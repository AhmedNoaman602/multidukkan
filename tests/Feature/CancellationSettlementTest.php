<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LedgerService;
use App\Support\LocalDateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cancelling an invoice settles its money in one step. Every cancel restores the stock at its
 * sale cost, gives store credit back (CREDIT_APPLY) and reverses the rest of the charge
 * (REVERSAL), so the invoice owes nothing and what was paid (net of earlier refunds) is the
 * customer's credit. The user then picks one settlement for that whole amount: credit (nothing
 * more is written) or cash (cash payments refunded through issueRefund, restored store credit
 * paid out as CREDIT_PAYOUT). Walk-in invoices are cash only; a received return offset blocks it.
 */
class CancellationSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Warehouse $shelf;
    protected User $admin;
    protected User $manager;
    protected User $staff;
    protected Customer $customer;
    protected Customer $walkIn;
    protected Product $product;
    protected LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->store  = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->shelf  = Warehouse::factory()->shelf()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => null, 'role' => 'tenant_admin',
        ]);
        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'role' => 'store_manager',
        ]);
        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'role' => 'store_staff',
        ]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->walkIn   = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'is_walk_in' => true]);

        // 50 a piece, cost 10: two pieces make an invoice of 100.
        $this->product = Product::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'price'      => 50,
            'cost_price' => 10,
        ]);
        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->shelf->id,
            'product_id'   => $this->product->id,
            'quantity'     => 100,
        ]);
        $this->ledger = app(LedgerService::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function sell(int $pieces = 2, ?Customer $customer = null, array $extra = []): Order
    {
        $id = $this->actingAs($this->admin)->postJson('/api/orders', array_merge([
            'store_id'    => $this->store->id,
            'customer_id' => ($customer ?? $this->customer)->id,
            'order_date'  => LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => $pieces]],
        ], $extra))->assertStatus(201)->json('id');

        return Order::findOrFail($id);
    }

    private function payCash(Order $order, float $amount): Payment
    {
        $this->actingAs($this->admin)->postJson('/api/payments', [
            'order_id'    => $order->id,
            'customer_id' => $order->customer_id,
            'amount'      => $amount,
            'method'      => 'cash',
        ])->assertStatus(201);

        return Payment::where('order_id', $order->id)->cashOnly()->latest('id')->firstOrFail();
    }

    /** Store credit from an overpayment with no open invoice. */
    private function giveCredit(float $amount): void
    {
        $this->actingAs($this->admin)->postJson('/api/payments/auto', [
            'customer_id' => $this->customer->id,
            'amount'      => $amount,
            'method'      => 'cash',
        ])->assertStatus(201);
    }

    private function refund(array $target, float $amount, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson("/api/customers/{$this->customer->id}/refund", $target + [
            'amount' => $amount,
            'method' => 'cash',
        ]);
    }

    private function cancel(Order $order, ?string $settlement, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson(
            "/api/orders/{$order->id}/cancel",
            $settlement === null ? [] : ['settlement' => $settlement]
        );
    }

    private function balance(?Customer $customer = null): float
    {
        return $this->ledger->getBalance($this->tenant->id, ($customer ?? $this->customer)->id);
    }

    /** Sum of one ledger type for this customer. */
    private function ledgerSum(string $type, ?Customer $customer = null): float
    {
        return (float) DB::table('ledger_entries')
            ->where('customer_id', ($customer ?? $this->customer)->id)
            ->where('type', $type)
            ->sum('amount');
    }

    private function ledgerCount(string $type): int
    {
        return DB::table('ledger_entries')->where('type', $type)->count();
    }

    private function stock(): int
    {
        return (int) Inventory::where('product_id', $this->product->id)->sum('quantity');
    }

    /** Everything a cancel can touch, for "nothing changed" checks. */
    private function snapshot(): array
    {
        $rows = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return [
            'ledger'    => $rows('ledger_entries'),
            'payments'  => $rows('payments'),
            'orders'    => $rows('orders'),
            'inventory' => $rows('inventory'),
            'movements' => $rows('inventory_transactions'),
            'products'  => $rows('products'),
        ];
    }

    // ─── Unpaid ──────────────────────────────────────────────────────────────

    public function test_an_unpaid_invoice_is_cancelled_without_a_settlement(): void
    {
        $order = $this->sell();

        $this->cancel($order, null)->assertOk()->assertJsonPath('message', __('messages.order_cancelled'));

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertEquals(100, $this->ledgerSum('REVERSAL'));
        $this->assertSame(0, $this->ledgerCount('REFUND'));
        $this->assertSame(0, $this->ledgerCount('CREDIT_PAYOUT'));
        $this->assertEquals(0, $this->balance());
        $this->assertSame(100, $this->stock());
    }

    public function test_a_settlement_sent_for_an_unpaid_invoice_moves_no_money(): void
    {
        $order = $this->sell();

        $this->cancel($order, 'cash')->assertOk();

        $this->assertSame(0, $this->ledgerCount('REFUND'));
        $this->assertSame(0, $this->ledgerCount('CREDIT_PAYOUT'));
        $this->assertEquals(0, $this->balance());
    }

    // ─── Cash payments ───────────────────────────────────────────────────────

    public function test_partly_paid_in_cash_to_credit_reverses_the_unpaid_part_and_keeps_the_cash_as_credit(): void
    {
        $order   = $this->sell();
        $payment = $this->payCash($order, 60);

        $this->cancel($order, 'credit')->assertOk();

        $this->assertEquals(100, $this->ledgerSum('REVERSAL'));
        $this->assertSame(0, $this->ledgerCount('REFUND'));
        $this->assertEquals(-60, $this->balance(), 'The 60 paid is now the customer\'s credit.');
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => 60, 'refunded_amount' => 0]);
    }

    public function test_partly_paid_in_cash_to_cash_refunds_what_was_paid(): void
    {
        $order   = $this->sell();
        $payment = $this->payCash($order, 60);

        $this->cancel($order, 'cash')->assertOk();

        $this->assertEquals(100, $this->ledgerSum('REVERSAL'));
        $this->assertEquals(60, $this->ledgerSum('REFUND'));
        $this->assertSame(0, $this->ledgerCount('CREDIT_PAYOUT'));
        $this->assertEquals(0, $this->balance());
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => 60, 'refunded_amount' => 60]);
    }

    public function test_fully_paid_in_cash_to_credit(): void
    {
        $order = $this->sell();
        $this->payCash($order, 100);

        $this->cancel($order, 'credit')->assertOk();

        $this->assertEquals(-100, $this->balance());
        $this->assertSame(0, $this->ledgerCount('REFUND'));
    }

    public function test_fully_paid_in_cash_to_cash(): void
    {
        $order = $this->sell();
        $this->payCash($order, 100);

        $this->cancel($order, 'cash')->assertOk();

        $this->assertEquals(100, $this->ledgerSum('REFUND'));
        $this->assertEquals(0, $this->balance());
    }

    public function test_an_earlier_refund_is_subtracted_from_a_cash_settlement(): void
    {
        $order   = $this->sell();
        $payment = $this->payCash($order, 100);
        $this->refund(['order_id' => $order->id], 30)->assertOk();

        $this->cancel($order, 'cash')->assertOk();

        $this->assertSame(2, $this->ledgerCount('REFUND'));
        $this->assertEquals(100, $this->ledgerSum('REFUND'), '30 earlier + 70 now, never 30 + 100.');
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'refunded_amount' => 100]);
        $this->assertEquals(0, $this->balance());
    }

    public function test_an_earlier_refund_is_subtracted_from_a_credit_settlement(): void
    {
        $order = $this->sell();
        $this->payCash($order, 100);
        $this->refund(['order_id' => $order->id], 30)->assertOk();

        $this->cancel($order, 'credit')->assertOk();

        $this->assertEquals(-70, $this->balance());
        $this->assertSame(1, $this->ledgerCount('REFUND'));
    }

    // ─── Store credit and mixed ──────────────────────────────────────────────

    public function test_paid_by_store_credit_to_credit_restores_the_credit(): void
    {
        $this->giveCredit(100);
        $order = $this->sell();
        $creditPayment = $order->payments()->creditOnly()->sole();

        $this->cancel($order, 'credit')->assertOk();

        $this->assertEquals(-100, $this->balance());
        $this->assertSame(0, $this->ledgerCount('REVERSAL'), 'Nothing unpaid is left to reverse.');
        $this->assertSame(0, $this->ledgerCount('CREDIT_PAYOUT'));
        $this->assertDatabaseHas('payments', ['id' => $creditPayment->id, 'amount' => 100]);
    }

    public function test_paid_by_store_credit_to_cash_restores_then_pays_out_the_credit(): void
    {
        $this->giveCredit(100);
        $order = $this->sell();
        $paymentsBefore = DB::table('payments')->count();

        $this->cancel($order, 'cash')->assertOk();

        $this->assertDatabaseHas('ledger_entries', [
            'type' => 'CREDIT_APPLY', 'amount' => 100, 'reference_type' => 'order', 'reference_id' => $order->id,
        ]);
        $this->assertDatabaseHas('ledger_entries', [
            'type' => 'CREDIT_PAYOUT', 'amount' => 100, 'reference_type' => 'order', 'reference_id' => $order->id,
        ]);
        $this->assertSame(0, $this->ledgerCount('REFUND'), 'Store credit is paid out, not refunded.');
        $this->assertEquals(0, $this->balance());
        $this->assertSame($paymentsBefore, DB::table('payments')->count());
    }

    public function test_mixed_cash_and_store_credit_to_cash(): void
    {
        $this->giveCredit(40);
        $order = $this->sell();
        $this->payCash($order, 60);

        $this->cancel($order, 'cash')->assertOk();

        $this->assertEquals(60, $this->ledgerSum('REVERSAL'));
        $this->assertEquals(60, $this->ledgerSum('REFUND'));
        $this->assertEquals(40, $this->ledgerSum('CREDIT_PAYOUT'));
        $this->assertEquals(0, $this->balance());
    }

    public function test_mixed_cash_and_store_credit_to_credit(): void
    {
        $this->giveCredit(40);
        $order = $this->sell();
        $this->payCash($order, 60);

        $this->cancel($order, 'credit')->assertOk();

        $this->assertEquals(60, $this->ledgerSum('REVERSAL'));
        $this->assertSame(0, $this->ledgerCount('REFUND'));
        $this->assertSame(0, $this->ledgerCount('CREDIT_PAYOUT'));
        $this->assertEquals(-100, $this->balance());
    }

    public function test_store_credit_payment_rows_are_kept_and_every_ledger_reference_still_resolves(): void
    {
        $this->giveCredit(40);
        $order = $this->sell();
        $this->payCash($order, 60);

        $this->cancel($order, 'credit')->assertOk();

        $this->assertSame(2, Payment::where('order_id', $order->id)->count());
        $dangling = DB::table('ledger_entries')
            ->where('reference_type', 'payment')
            ->whereNotIn('reference_id', DB::table('payments')->select(DB::raw('CAST(id AS CHAR)')))
            ->count();
        $this->assertSame(0, $dangling, 'Every ledger entry that references a payment must still find it.');
    }

    // ─── Validation, walk-in, permissions ───────────────────────────────────

    public function test_a_paid_invoice_needs_a_settlement_and_nothing_changes_without_one(): void
    {
        $order = $this->sell();
        $this->payCash($order, 60);
        $before = $this->snapshot();

        $this->cancel($order, null)
            ->assertStatus(422)
            ->assertJsonPath('errors.settlement.0', __('messages.cancel_settlement_required'));

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_an_unknown_settlement_is_rejected(): void
    {
        $order  = $this->sell();
        $before = $this->snapshot();

        $this->cancel($order, 'voucher')->assertStatus(422)->assertJsonValidationErrors('settlement');

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_a_walk_in_invoice_cannot_be_settled_as_credit(): void
    {
        $order = $this->sell(2, $this->walkIn, ['pay_immediately' => true, 'payment_method' => 'cash']);
        $before = $this->snapshot();

        $this->cancel($order, 'credit')
            ->assertStatus(422)
            ->assertJsonPath('errors.settlement.0', __('messages.cancel_walk_in_cash_only'));

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_a_walk_in_invoice_is_settled_in_cash(): void
    {
        $order = $this->sell(2, $this->walkIn, ['pay_immediately' => true, 'payment_method' => 'cash']);

        $this->cancel($order, 'cash')->assertOk();

        $this->assertEquals(100, $this->ledgerSum('REFUND', $this->walkIn));
        $this->assertEquals(0, $this->balance($this->walkIn));
    }

    public function test_an_unpaid_walk_in_invoice_needs_no_settlement(): void
    {
        $order = $this->sell(2, $this->walkIn);

        $this->cancel($order, null)->assertOk();

        $this->assertEquals(0, $this->balance($this->walkIn));
    }

    public function test_staff_cannot_cancel_and_nothing_changes(): void
    {
        $order = $this->sell();
        $this->payCash($order, 100);
        $before = $this->snapshot();

        $this->cancel($order, 'cash', $this->staff)->assertStatus(403);

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_the_store_manager_can_cancel_with_a_cash_settlement(): void
    {
        $order = $this->sell();
        $this->payCash($order, 100);

        $this->cancel($order, 'cash', $this->manager)->assertOk();

        $this->assertEquals(100, $this->ledgerSum('REFUND'));
        $this->assertEquals(0, $this->balance());
    }

    // ─── Other outstanding invoices ──────────────────────────────────────────

    /**
     * Credit 40 pays part of invoice A (100). Later the customer buys B (50) on account.
     * Cancelling A gives the 40 back as credit, but the customer owes 50 on B, so there is
     * no available credit to pay out in cash: the cash settlement is refused, nothing written.
     */
    public function test_store_credit_cannot_be_paid_out_in_cash_while_the_customer_owes_more(): void
    {
        $this->giveCredit(40);
        $orderA = $this->sell();
        $this->sell(1);
        $before = $this->snapshot();

        $this->cancel($orderA, 'cash')
            ->assertStatus(422)
            ->assertJsonPath('errors.settlement.0', __('messages.cancel_credit_payout_unavailable', [
                'amount' => '40.00', 'available' => '0.00',
            ]));

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_the_same_invoice_settled_as_credit_nets_against_the_other_debt(): void
    {
        $this->giveCredit(40);
        $orderA = $this->sell();
        $orderB = $this->sell(1);

        $this->cancel($orderA, 'credit')->assertOk();

        // 50 owed on B − 40 credit back from A.
        $this->assertEquals(10, $this->balance());
        $this->assertSame(0, $this->ledgerCount('CREDIT_PAYOUT'));
        // Existing rule: credit is a balance, it doesn't create payments on other invoices.
        $this->assertSame(0, Payment::where('order_id', $orderB->id)->count());
    }

    public function test_cash_paid_on_the_invoice_is_refunded_even_while_other_invoices_are_owed(): void
    {
        $orderA = $this->sell();
        $this->payCash($orderA, 100);
        $this->sell(1);

        $this->cancel($orderA, 'cash')->assertOk();

        $this->assertEquals(100, $this->ledgerSum('REFUND'));
        $this->assertEquals(50, $this->balance(), 'Only B is still owed.');
    }

    public function test_the_cash_payout_goes_through_once_the_other_invoices_are_settled(): void
    {
        $this->giveCredit(150);   // 100 pays A, 50 pays B
        $orderA = $this->sell();
        $this->sell(1);

        $this->cancel($orderA, 'cash')->assertOk();

        $this->assertEquals(100, $this->ledgerSum('CREDIT_PAYOUT'));
        $this->assertEquals(0, $this->balance());
    }

    public function test_a_cash_payout_is_capped_by_a_small_other_debt_too(): void
    {
        $this->giveCredit(140);   // 100 pays A, 40 pays part of B
        $orderA = $this->sell();
        $this->sell(1);           // B 50: 10 still owed
        $before = $this->snapshot();

        $this->cancel($orderA, 'cash')
            ->assertStatus(422)
            ->assertJsonPath('errors.settlement.0', __('messages.cancel_credit_payout_unavailable', [
                'amount' => '100.00', 'available' => '90.00',
            ]));

        $this->assertEquals($before, $this->snapshot());
    }

    // ─── Return offsets (D5) and duplicates ──────────────────────────────────

    public function test_an_invoice_with_a_received_return_offset_cannot_be_cancelled(): void
    {
        $order = $this->sell();
        Payment::factory()->returnOffset()->create([
            'tenant_id'   => $this->tenant->id,
            'order_id'    => $order->id,
            'customer_id' => $this->customer->id,
            'amount'      => 30,
            'paid_at'     => now(),
        ]);
        $before = $this->snapshot();

        foreach ([null, 'cash', 'credit'] as $settlement) {
            $this->cancel($order, $settlement)
                ->assertStatus(422)
                ->assertJsonPath('message', __('messages.cancel_has_return_offset'));
        }

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_cancelling_twice_is_refused_and_settles_once(): void
    {
        $order = $this->sell();
        $this->payCash($order, 100);
        $this->cancel($order, 'cash')->assertOk();
        $before = $this->snapshot();

        $this->cancel($order, 'cash')
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.order_already_cancelled'));

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame(1, $this->ledgerCount('REVERSAL'));
        $this->assertSame(1, $this->ledgerCount('REFUND'));
    }

    public function test_cancelling_twice_never_restores_store_credit_twice(): void
    {
        $this->giveCredit(100);
        $order = $this->sell();
        $this->cancel($order, 'credit')->assertOk();

        $this->cancel($order, 'credit')->assertStatus(422);

        $this->assertSame(1, DB::table('ledger_entries')
            ->where('type', 'CREDIT_APPLY')->where('reference_type', 'order')->where('reference_id', $order->id)->count());
        $this->assertEquals(-100, $this->balance());
    }

    public function test_the_old_delete_route_cancels_an_unpaid_invoice_and_asks_for_a_settlement_otherwise(): void
    {
        $unpaid = $this->sell();
        $this->actingAs($this->admin)->deleteJson("/api/orders/{$unpaid->id}")->assertOk();
        $this->assertSoftDeleted('orders', ['id' => $unpaid->id]);

        $paid = $this->sell();
        $this->payCash($paid, 100);
        $this->actingAs($this->admin)->deleteJson("/api/orders/{$paid->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.settlement.0', __('messages.cancel_settlement_required'));
        $this->assertNotSoftDeleted('orders', ['id' => $paid->id]);
    }

    // ─── Stock and average cost ──────────────────────────────────────────────

    public function test_cancel_restores_the_stock_at_the_sale_cost_and_recalculates_the_average(): void
    {
        $order = $this->sell();   // 2 sold at cost 10, stock 98
        $this->payCash($order, 100);

        $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->admin)->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items'       => [[
                'product_id'   => $this->product->id,
                'quantity'     => 100,
                'unit_price'   => 20,
                'unit_type'    => 'base',
                'warehouse_id' => $this->shelf->id,
            ]],
        ])->assertStatus(201);
        // (98 × 10 + 100 × 20) / 198

        $this->cancel($order, 'cash')->assertOk();

        // (2980 + 2 × 10) / 200 = 15
        $this->assertSame(200, $this->stock());
        $this->assertEquals('15.0000', $this->product->fresh()->cost_price);
    }

    // ─── Preview ─────────────────────────────────────────────────────────────

    public function test_the_preview_shows_the_settlement_amounts_and_writes_nothing(): void
    {
        $this->giveCredit(40);
        $order = $this->sell();
        $this->payCash($order, 30);
        $before = $this->snapshot();

        $this->actingAs($this->admin)->getJson("/api/orders/{$order->id}/cancellation")
            ->assertOk()
            ->assertJsonPath('data.total', 100)
            ->assertJsonPath('data.cash_paid', 30)
            ->assertJsonPath('data.credit_paid', 40)
            ->assertJsonPath('data.refundable', 70)
            ->assertJsonPath('data.unpaid_reversed', 30)
            ->assertJsonPath('data.settlement_required', true)
            ->assertJsonPath('data.settlements', ['cash', 'credit'])
            ->assertJsonPath('data.cash_payout_available', true)
            ->assertJsonPath('data.blocked_reason', null);

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_the_preview_offers_walk_in_invoices_cash_only(): void
    {
        $order = $this->sell(2, $this->walkIn, ['pay_immediately' => true, 'payment_method' => 'cash']);

        $this->actingAs($this->admin)->getJson("/api/orders/{$order->id}/cancellation")
            ->assertOk()
            ->assertJsonPath('data.settlements', ['cash']);
    }

    public function test_the_preview_tells_when_store_credit_cannot_be_paid_out(): void
    {
        $this->giveCredit(40);
        $orderA = $this->sell();
        $this->sell(1);

        $this->actingAs($this->admin)->getJson("/api/orders/{$orderA->id}/cancellation")
            ->assertOk()
            ->assertJsonPath('data.credit_paid', 40)
            ->assertJsonPath('data.cash_payout_available', false);
    }

    public function test_the_preview_reports_a_return_offset_block_and_needs_cancel_permission(): void
    {
        $order = $this->sell();
        Payment::factory()->returnOffset()->create([
            'tenant_id' => $this->tenant->id, 'order_id' => $order->id,
            'customer_id' => $this->customer->id, 'amount' => 30, 'paid_at' => now(),
        ]);

        $this->actingAs($this->admin)->getJson("/api/orders/{$order->id}/cancellation")
            ->assertOk()
            ->assertJsonPath('data.blocked_reason', 'return_offset');

        $this->actingAs($this->staff)->getJson("/api/orders/{$order->id}/cancellation")->assertStatus(403);
    }
}
