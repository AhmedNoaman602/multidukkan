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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An invoice's date can change only on the shop's business day it was created. After that day the
 * date is locked, so past reports stay as seen.
 */
class SameDayInvoiceRulesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $admin;
    protected Warehouse $shelf;
    protected Customer $customer;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->store  = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin  = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
        $this->shelf = Warehouse::factory()->shelf()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'sku'        => 'SKU-'.uniqid(),
            'price'      => 20,
            'cost_price' => 10,
        ]);
        Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->shelf->id,
            'product_id'   => $this->product->id,
            'quantity'     => 50,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $cairoTime): void
    {
        Carbon::setTestNow(Carbon::parse($cairoTime, 'Africa/Cairo')->utc());
    }

    /** Creates an invoice at the frozen clock, dated that business day. */
    private function invoiceAt(string $cairoTime): Order
    {
        $this->at($cairoTime);

        $id = $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => Carbon::parse($cairoTime, 'Africa/Cairo')->toDateString(),
            'items'       => [['product_id' => $this->product->id, 'quantity' => 2]],
        ])->assertStatus(201)->json('id');

        return Order::findOrFail($id);
    }

    private function storedDate(Order $order): string
    {
        return DB::table('orders')->where('id', $order->id)->value('order_date');
    }

    public function test_the_date_can_change_on_the_day_the_invoice_was_created(): void
    {
        $order = $this->invoiceAt('2026-11-20 10:00');
        $this->at('2026-11-20 18:00');

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$order->id}", ['order_date' => '2026-11-18'])
            ->assertOk();

        $this->assertSame('2026-11-18', $this->storedDate($order));
    }

    public function test_the_date_is_locked_after_the_creation_day(): void
    {
        $order = $this->invoiceAt('2026-11-20 10:00');
        $this->at('2026-11-21 09:00');

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$order->id}", ['order_date' => '2026-11-18'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.order_date_locked'));

        $this->assertSame('2026-11-20', $this->storedDate($order), 'A refused change must leave the date as it was.');
    }

    public function test_notes_can_still_be_saved_with_the_unchanged_date_after_the_creation_day(): void
    {
        $order = $this->invoiceAt('2026-11-20 10:00');
        $this->at('2026-11-25 09:00');

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$order->id}", ['order_date' => '2026-11-20', 'notes' => 'Delivered'])
            ->assertOk();

        $this->assertSame('Delivered', DB::table('orders')->where('id', $order->id)->value('notes'));
        $this->assertSame('2026-11-20', $this->storedDate($order));
    }

    public function test_the_creation_day_is_the_shop_s_day_not_utc_s(): void
    {
        // 23:30 and 00:30 Cairo (UTC+2) are both 21:30 / 22:30 UTC on the 20th: one UTC day, two shop days.
        $late = $this->invoiceAt('2026-11-20 23:30');
        $this->at('2026-11-21 00:30');

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$late->id}", ['order_date' => '2026-11-19'])
            ->assertStatus(422);

        // 00:30 Cairo is still the 20th in UTC (22:30 on the 20th): two UTC days, one shop day.
        $early = $this->invoiceAt('2026-11-21 00:30');
        $this->at('2026-11-21 23:00');

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$early->id}", ['order_date' => '2026-11-19'])
            ->assertOk();

        $this->assertSame('2026-11-20', $this->storedDate($late));
        $this->assertSame('2026-11-19', $this->storedDate($early));
    }

    public function test_a_backdated_invoice_counts_from_the_day_it_was_entered(): void
    {
        $this->at('2026-11-20 10:00');
        $id = $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => '2026-11-02',
            'items'       => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('id');

        $this->actingAs($this->admin)
            ->patchJson("/api/orders/{$id}", ['order_date' => '2026-11-03'])
            ->assertOk();

        $this->assertSame('2026-11-03', DB::table('orders')->where('id', $id)->value('order_date'));
    }

    public function test_the_order_tells_the_screen_whether_its_date_can_change(): void
    {
        $order = $this->invoiceAt('2026-11-20 10:00');

        $this->at('2026-11-20 22:00');
        $this->actingAs($this->admin)->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('date_editable', true);

        $this->at('2026-11-21 08:00');
        $this->actingAs($this->admin)->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('date_editable', false);
    }
}
