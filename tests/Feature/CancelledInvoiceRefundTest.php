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
 * A cancelled invoice has already settled its money, so no refund may be taken from it
 * afterwards — by order or by a specific payment. The risky case is a cancel settled as
 * customer credit: the cash payment row still shows its amount unrefunded (true for the
 * cash reports), so without this block the same money could also be paid out in cash.
 */
class CancelledInvoiceRefundTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $admin;
    protected User $manager;
    protected User $staff;
    protected Customer $customer;
    protected Product $product;
    protected LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->store  = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $shelf        = Warehouse::factory()->shelf()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        $this->admin   = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => null, 'role' => 'tenant_admin']);
        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'role' => 'store_manager']);
        $this->staff   = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'role' => 'store_staff']);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 50, 'cost_price' => 10]);
        Inventory::factory()->create([
            'tenant_id' => $this->tenant->id, 'warehouse_id' => $shelf->id,
            'product_id' => $this->product->id, 'quantity' => 100,
        ]);
        $this->ledger = app(LedgerService::class);
    }

    /** Invoice of 100, paid 60 in cash, cancelled as customer credit. */
    private function cancelledToCredit(): array
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

        $this->actingAs($this->admin)->postJson("/api/orders/{$orderId}/cancel", ['settlement' => 'credit'])->assertOk();

        return [Order::withTrashed()->findOrFail($orderId), Payment::where('order_id', $orderId)->sole()];
    }

    private function refund(array $target, float $amount, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson("/api/customers/{$this->customer->id}/refund", $target + [
            'amount' => $amount,
            'method' => 'cash',
        ]);
    }

    private function snapshot(): array
    {
        $rows = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return ['ledger' => $rows('ledger_entries'), 'payments' => $rows('payments')];
    }

    public function test_a_refund_by_order_is_refused_once_the_invoice_is_cancelled(): void
    {
        [$order] = $this->cancelledToCredit();
        $before = $this->snapshot();

        $this->refund(['order_id' => $order->id], 60)
            ->assertStatus(422)
            ->assertJsonPath('errors.order_id.0', __('messages.refund_order_cancelled'));

        $this->assertEquals($before, $this->snapshot());
        $this->assertEquals(-60, $this->ledger->getBalance($this->tenant->id, $this->customer->id));
    }

    public function test_a_refund_by_payment_is_refused_once_the_invoice_is_cancelled(): void
    {
        [, $payment] = $this->cancelledToCredit();
        $before = $this->snapshot();

        foreach ([$this->admin, $this->manager, $this->staff] as $user) {
            $this->refund(['payment_id_target' => $payment->id], 60, $user)
                ->assertStatus(422)
                ->assertJsonPath('errors.payment_id_target.0', __('messages.refund_order_cancelled'));
        }

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_the_refund_service_itself_refuses_a_cancelled_invoice(): void
    {
        [$order, $payment] = $this->cancelledToCredit();
        $before = $this->snapshot();
        $this->actingAs($this->admin);

        foreach ([['payment_id_target' => $payment->id], ['order_id' => $order->id]] as $target) {
            try {
                $this->ledger->issueRefund($target + [
                    'tenant_id'   => $this->tenant->id,
                    'customer_id' => $this->customer->id,
                    'store_id'    => $this->store->id,
                    'amount'      => 60,
                    'method'      => 'cash',
                ]);
                $this->fail('issueRefund must refuse a cancelled invoice.');
            } catch (ValidationException $e) {
                $this->assertSame(__('messages.refund_order_cancelled'), collect($e->errors())->flatten()->first());
            }
        }

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_refunds_on_an_active_invoice_still_work(): void
    {
        $orderId = $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => 2]],
            'pay_immediately' => true,
            'payment_method'  => 'cash',
        ])->assertStatus(201)->json('id');
        $paymentId = Payment::where('order_id', $orderId)->value('id');

        $this->refund(['payment_id_target' => $paymentId], 30, $this->staff)->assertOk();
        $this->refund(['order_id' => $orderId], 20)->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'refunded_amount' => 50]);
    }
}
