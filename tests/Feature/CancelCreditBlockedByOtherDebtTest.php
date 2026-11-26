<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
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
 * F-06 (MUL2-73). Cancelling a paid invoice with the credit settlement while the customer has
 * another unpaid invoice leaves the paid amount attached to no invoice: the ledger is right, but
 * the other invoice still shows its full amount unpaid. Until oldest-first allocation exists, that
 * one combination is refused; cash, unpaid cancels and credit with no other debt work as before.
 */
class CancelCreditBlockedByOtherDebtTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $admin;
    protected Customer $customer;
    protected Product $product;
    protected LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->store  = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $shelf        = Warehouse::factory()->shelf()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        $this->admin  = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => null, 'role' => 'tenant_admin']);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        // 100 a piece: X = 10 pieces = 1,000, Y = 5 pieces = 500.
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 100, 'cost_price' => 40]);
        Inventory::factory()->create([
            'tenant_id' => $this->tenant->id, 'warehouse_id' => $shelf->id,
            'product_id' => $this->product->id, 'quantity' => 100,
        ]);
        $this->ledger = app(LedgerService::class);
    }

    private function sell(int $pieces): Order
    {
        $id = $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id' => $this->store->id, 'customer_id' => $this->customer->id,
            'order_date' => LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => $pieces]],
        ])->assertStatus(201)->json('id');

        return Order::findOrFail($id);
    }

    private function payCash(Order $order, float $amount): void
    {
        $this->actingAs($this->admin)->postJson('/api/payments', [
            'order_id' => $order->id, 'customer_id' => $this->customer->id, 'amount' => $amount, 'method' => 'cash',
        ])->assertStatus(201);
    }

    private function cancel(Order $order, ?string $settlement)
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/orders/{$order->id}/cancel", $settlement === null ? [] : ['settlement' => $settlement]);
    }

    private function balance(): float
    {
        return $this->ledger->getBalance($this->tenant->id, $this->customer->id);
    }

    private function snapshot(): array
    {
        $rows = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return [$rows('ledger_entries'), $rows('payments'), $rows('orders'), $rows('inventory'), $rows('inventory_transactions')];
    }

    public function test_credit_is_refused_when_another_invoice_is_unpaid_and_nothing_changes(): void
    {
        $this->sell(5);              // Y, 500 unpaid
        $x = $this->sell(10);        // X, 1,000
        $this->payCash($x, 400);
        $before = $this->snapshot();

        $this->cancel($x, 'credit')
            ->assertStatus(422)
            ->assertJsonValidationErrors('settlement')
            ->assertJsonPath('errors.settlement.0', __('messages.cancel_credit_other_unpaid'));

        $this->assertSame($before, $this->snapshot(), 'A refused cancel must write nothing.');
        $this->assertNotSoftDeleted('orders', ['id' => $x->id]);
        $this->assertEquals(1100, $this->balance());
    }

    public function test_cash_is_still_allowed_when_another_invoice_is_unpaid(): void
    {
        $y = $this->sell(5);
        $x = $this->sell(10);
        $this->payCash($x, 400);

        $this->cancel($x, 'cash')->assertOk();

        $this->assertSoftDeleted('orders', ['id' => $x->id]);
        $this->assertEquals(500, $this->balance(), 'Only Y is owed; the 400 went back in cash.');
        $this->actingAs($this->admin)->getJson("/api/orders/{$y->id}")->assertJsonPath('amount_remaining', 500);
    }

    public function test_an_unpaid_invoice_is_still_cancelled_when_another_invoice_is_unpaid(): void
    {
        $this->sell(5);
        $x = $this->sell(10);

        $this->cancel($x, null)->assertOk();

        $this->assertSoftDeleted('orders', ['id' => $x->id]);
        $this->assertEquals(500, $this->balance());
    }

    public function test_credit_is_allowed_when_the_other_invoices_are_fully_paid(): void
    {
        $y = $this->sell(5);
        $this->payCash($y, 500);
        $x = $this->sell(10);
        $this->payCash($x, 400);

        $this->cancel($x, 'credit')->assertOk();

        $this->assertEquals(-400, $this->balance(), 'The 400 is visible customer credit.');
    }

    public function test_an_invoice_paid_with_store_credit_is_refused_credit_too_when_another_invoice_is_unpaid(): void
    {
        $this->actingAs($this->admin)->postJson('/api/payments/auto', [
            'customer_id' => $this->customer->id, 'amount' => 1000, 'method' => 'cash',
        ])->assertStatus(201);
        $x = $this->sell(10);        // paid fully from the 1,000 credit
        $this->sell(5);              // Y, 500 unpaid
        $before = $this->snapshot();

        $this->cancel($x, 'credit')->assertStatus(422)->assertJsonValidationErrors('settlement');

        $this->assertSame($before, $this->snapshot());
    }

    public function test_the_preview_tells_the_screen_why_credit_is_not_offered(): void
    {
        $this->sell(5);
        $x = $this->sell(10);
        $this->payCash($x, 400);

        $this->actingAs($this->admin)->getJson("/api/orders/{$x->id}/cancellation")
            ->assertOk()
            ->assertJsonPath('data.credit_blocked_reason', 'other_unpaid_invoices');
    }

    public function test_the_preview_offers_credit_when_nothing_else_is_owed(): void
    {
        $x = $this->sell(10);
        $this->payCash($x, 400);

        $this->actingAs($this->admin)->getJson("/api/orders/{$x->id}/cancellation")
            ->assertOk()
            ->assertJsonPath('data.credit_blocked_reason', null);
    }
}
