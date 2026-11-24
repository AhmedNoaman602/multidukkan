<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LedgerService;
use App\Support\LocalDateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The shared walk-in account never holds or spends customer credit: walk-in payments can't
 * exceed what is owed, and walk-in sales never use credit. That keeps every walk-in invoice
 * cancellable in cash (walk-in cancellations are cash-only and credit is never paid out to a
 * walk-in). Ordinary customers keep their overpayment credit and spend it on the next sale.
 */
class WalkInCreditTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $admin;
    protected Customer $walkIn;
    protected Customer $customer;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $shelf          = Warehouse::factory()->shelf()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        $this->admin    = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => null, 'role' => 'tenant_admin']);
        $this->walkIn   = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'is_walk_in' => true]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 50, 'cost_price' => 10]);
        Inventory::factory()->create([
            'tenant_id' => $this->tenant->id, 'warehouse_id' => $shelf->id,
            'product_id' => $this->product->id, 'quantity' => 100,
        ]);
    }

    private function sell(Customer $customer, int $pieces = 2): Order
    {
        $id = $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $customer->id,
            'order_date'  => LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => $pieces]],
        ])->assertStatus(201)->json('id');

        return Order::findOrFail($id);
    }

    private function pay(Order $order, float $amount)
    {
        return $this->actingAs($this->admin)->postJson('/api/payments', [
            'order_id' => $order->id, 'customer_id' => $order->customer_id, 'amount' => $amount, 'method' => 'cash',
        ]);
    }

    private function autoPay(Customer $customer, float $amount)
    {
        return $this->actingAs($this->admin)->postJson('/api/payments/auto', [
            'customer_id' => $customer->id, 'amount' => $amount, 'method' => 'cash',
        ]);
    }

    private function balance(Customer $customer): float
    {
        return app(LedgerService::class)->getBalance($this->tenant->id, $customer->id);
    }

    private function snapshot(): array
    {
        $rows = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return ['ledger' => $rows('ledger_entries'), 'payments' => $rows('payments')];
    }

    // ─── Payments ────────────────────────────────────────────────────────────

    public function test_a_walk_in_direct_payment_above_what_is_owed_is_refused_and_writes_nothing(): void
    {
        $order = $this->sell($this->walkIn);
        $this->pay($order, 30)->assertStatus(201);
        $before = $this->snapshot();

        $this->pay($order, 70.01)
            ->assertStatus(422)
            ->assertJsonPath('errors.amount.0', __('messages.walk_in_overpayment', ['remaining' => '70.00']));

        $this->assertEquals($before, $this->snapshot());
        $this->assertEquals(70, $this->balance($this->walkIn));
    }

    public function test_a_walk_in_direct_payment_of_exactly_what_is_owed_is_accepted(): void
    {
        $order = $this->sell($this->walkIn);

        $this->pay($order, 100)->assertStatus(201);

        $this->assertEquals(0, $this->balance($this->walkIn));
        $this->assertSame(0, LedgerEntry::where('customer_id', $this->walkIn->id)->where('type', 'CREDIT_APPLY')->count());
    }

    public function test_a_walk_in_auto_payment_with_an_excess_is_refused_and_writes_nothing(): void
    {
        $this->sell($this->walkIn);
        $before = $this->snapshot();

        $this->autoPay($this->walkIn, 150)
            ->assertStatus(422)
            ->assertJsonPath('errors.amount.0', __('messages.walk_in_overpayment', ['remaining' => '100.00']));

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_a_walk_in_auto_payment_within_what_is_owed_is_accepted(): void
    {
        $this->sell($this->walkIn);
        $this->sell($this->walkIn, 1);

        $this->autoPay($this->walkIn, 150)->assertStatus(201);

        $this->assertEquals(0, $this->balance($this->walkIn));
    }

    public function test_an_ordinary_customer_can_still_overpay_into_credit(): void
    {
        $order = $this->sell($this->customer);

        $this->pay($order, 150)->assertStatus(201);
        $this->autoPay($this->customer, 20)->assertStatus(201);

        $this->assertEquals(-70, $this->balance($this->customer));
    }

    // ─── Sales ───────────────────────────────────────────────────────────────

    /** Credit already on the walk-in account (older data) is never spent by a sale. */
    public function test_a_walk_in_sale_never_spends_credit(): void
    {
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->walkIn->id, 'store_id' => $this->store->id,
            'type' => 'CREDIT_APPLY', 'amount' => 50, 'reference_type' => 'manual', 'reference_id' => $this->walkIn->id,
        ]);

        $order = $this->sell($this->walkIn);

        $this->assertSame(0, Payment::where('order_id', $order->id)->count());
        $this->assertSame(0, LedgerEntry::where('type', 'CREDIT_CONSUMED')->count());
        $this->assertEquals(50, $this->balance($this->walkIn), '100 charged, the 50 old credit untouched.');
    }

    public function test_a_walk_in_quick_sale_is_paid_fully_in_cash_even_with_old_credit(): void
    {
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->walkIn->id, 'store_id' => $this->store->id,
            'type' => 'CREDIT_APPLY', 'amount' => 50, 'reference_type' => 'manual', 'reference_id' => $this->walkIn->id,
        ]);

        $id = $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'        => $this->store->id,
            'customer_id'     => $this->walkIn->id,
            'order_date'      => LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString(),
            'items'           => [['product_id' => $this->product->id, 'quantity' => 2]],
            'pay_immediately' => true,
            'payment_method'  => 'cash',
        ])->assertStatus(201)->json('id');

        $this->assertDatabaseHas('payments', ['order_id' => $id, 'amount' => 100, 'counts_as_cash' => true]);
        $this->assertSame(0, Payment::where('order_id', $id)->creditOnly()->count());
    }

    public function test_an_ordinary_customer_sale_still_spends_credit(): void
    {
        $this->autoPay($this->customer, 40)->assertStatus(201);

        $order = $this->sell($this->customer);

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'amount' => 40, 'is_auto_reversible' => true]);
        $this->assertEquals(60, $this->balance($this->customer));
    }

    // ─── Cancellation ────────────────────────────────────────────────────────

    /** The dead end from the Phase 2 review: overpay, next sale, cancel. Now cancellable in cash. */
    public function test_a_walk_in_invoice_stays_cancellable_in_cash_after_an_overpayment_attempt(): void
    {
        $first = $this->sell($this->walkIn);
        $this->pay($first, 150)->assertStatus(422);
        $this->pay($first, 100)->assertStatus(201);

        $second = $this->sell($this->walkIn);
        $this->pay($second, 100)->assertStatus(201);

        $this->actingAs($this->admin)->postJson("/api/orders/{$second->id}/cancel", ['settlement' => 'cash'])->assertOk();

        $this->assertEquals(100, LedgerEntry::where('customer_id', $this->walkIn->id)->where('type', 'REFUND')->sum('amount'));
        $this->assertSame(0, LedgerEntry::where('customer_id', $this->walkIn->id)->where('type', 'CREDIT_PAYOUT')->count());
        $this->assertEquals(0, $this->balance($this->walkIn));
    }

    public function test_old_walk_in_credit_does_not_block_a_cash_cancellation(): void
    {
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->walkIn->id, 'store_id' => $this->store->id,
            'type' => 'CREDIT_APPLY', 'amount' => 50, 'reference_type' => 'manual', 'reference_id' => $this->walkIn->id,
        ]);
        $order = $this->sell($this->walkIn);
        $this->pay($order, 100)->assertStatus(201);

        $this->actingAs($this->admin)->postJson("/api/orders/{$order->id}/cancel", ['settlement' => 'cash'])->assertOk();

        $this->assertEquals(-50, $this->balance($this->walkIn), 'Only the old credit is left, untouched.');
    }
}
