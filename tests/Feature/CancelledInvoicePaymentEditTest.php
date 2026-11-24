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
use App\Support\LocalDateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A cancelled invoice settled its money when it was cancelled, so its payments can't be
 * edited afterwards. Settled as customer credit, the cash payment stays unrefunded, so the
 * "refunded payments can't be edited" rule alone doesn't stop it. Editing stays owner-only
 * and keeps working on active invoices.
 */
class CancelledInvoicePaymentEditTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $admin;
    protected User $manager;
    protected User $staff;
    protected Customer $customer;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $shelf          = Warehouse::factory()->shelf()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        $this->admin    = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => null, 'role' => 'tenant_admin']);
        $this->manager  = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'role' => 'store_manager']);
        $this->staff    = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'role' => 'store_staff']);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 50, 'cost_price' => 10]);
        Inventory::factory()->create([
            'tenant_id' => $this->tenant->id, 'warehouse_id' => $shelf->id,
            'product_id' => $this->product->id, 'quantity' => 100,
        ]);
    }

    /** Invoice of 100 paid 60 in cash; optionally cancelled with the given settlement. */
    private function paidInvoice(?string $cancelWith = null): Payment
    {
        $orderId = $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => 2]],
        ])->assertStatus(201)->json('id');

        $this->actingAs($this->admin)->postJson('/api/payments', [
            'order_id' => $orderId, 'customer_id' => $this->customer->id, 'amount' => 60, 'method' => 'cash',
        ])->assertStatus(201);

        if ($cancelWith !== null) {
            $this->actingAs($this->admin)->postJson("/api/orders/{$orderId}/cancel", ['settlement' => $cancelWith])->assertOk();
        }

        return Payment::where('order_id', $orderId)->sole();
    }

    private function edit(Payment $payment, float $amount, User $as)
    {
        return $this->actingAs($as)->patchJson("/api/payments/{$payment->id}", ['amount' => $amount, 'method' => 'cash']);
    }

    private function snapshot(): array
    {
        $rows = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return ['ledger' => $rows('ledger_entries'), 'payments' => $rows('payments')];
    }

    public function test_the_owner_cannot_edit_a_payment_of_a_credit_cancelled_invoice(): void
    {
        $payment = $this->paidInvoice('credit');
        $before  = $this->snapshot();

        foreach ([600, 10] as $amount) {
            $this->edit($payment, $amount, $this->admin)
                ->assertStatus(422)
                ->assertJsonPath('errors.payment.0', __('messages.payment_order_cancelled'));
        }

        $this->assertEquals($before, $this->snapshot());
        $this->assertEquals(-60, app(LedgerService::class)->getBalance($this->tenant->id, $this->customer->id));
    }

    public function test_the_owner_cannot_edit_a_payment_of_a_cash_cancelled_invoice(): void
    {
        $payment = $this->paidInvoice('cash');
        $before  = $this->snapshot();

        $this->edit($payment, 50, $this->admin)
            ->assertStatus(422)
            ->assertJsonPath('errors.payment.0', __('messages.payment_order_cancelled'));

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_managers_and_staff_still_cannot_edit_payments(): void
    {
        $active    = $this->paidInvoice();          // first: the later cancel's credit would pay part of it
        $cancelled = $this->paidInvoice('credit');
        $before    = $this->snapshot();

        foreach ([$this->manager, $this->staff] as $user) {
            $this->edit($cancelled, 50, $user)->assertStatus(403);
            $this->edit($active, 50, $user)->assertStatus(403);
        }

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_the_owner_can_still_edit_a_payment_of_an_active_invoice(): void
    {
        $payment = $this->paidInvoice();

        $this->edit($payment, 50, $this->admin)->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => 50]);
        $this->assertDatabaseHas('ledger_entries', [
            'type' => 'PAYMENT', 'reference_type' => 'payment', 'reference_id' => $payment->id, 'amount' => 50,
        ]);
    }

    public function test_the_payment_service_itself_refuses_a_cancelled_invoice(): void
    {
        $payment = $this->paidInvoice('credit');
        $before  = $this->snapshot();
        $this->actingAs($this->admin);

        try {
            app(LedgerService::class)->adjustPayment($payment, 600, 'cash');
            $this->fail('adjustPayment must refuse a payment of a cancelled invoice.');
        } catch (ValidationException $e) {
            $this->assertSame([__('messages.payment_order_cancelled')], $e->errors()['payment']);
        }

        $this->assertEquals($before, $this->snapshot());
    }
}
