<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\LocalDateRange;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Instants are generated, stored and serialized in UTC; only the *calendar day*
 * is interpreted in the viewer's zone. These tests pin down both halves.
 *
 * The canonical fixture throughout is the case that surfaced the bug:
 * 22:04 on 2026-08-21 in Cairo == 19:04:47 UTC (Egypt is UTC+3 in August).
 *
 * NOTE: the suite runs on SQLite (phpunit.xml), which is typeless for dates and has
 * no session timezone. The MySQL storage layer — DATETIME columns and the '+00:00'
 * connection pin — is therefore NOT covered here; verify it against MySQL with
 * SHOW COLUMNS after a migrate:fresh.
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    private const CAIRO = 'Africa/Cairo';

    /** 22:04 Cairo, the moment from the original bug report. */
    private const EVENT_UTC = '2026-08-21 19:04:47.864591';
    private const EVENT_LOCAL_DATE = '2026-08-21';

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
        $this->product  = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 100]);
        $this->user     = User::factory()->create([
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Requests carry the viewer's zone the way the axios interceptor sends it. */
    private function asCairoUser()
    {
        return $this->actingAs($this->user)->withHeaders([
            'X-Timezone' => self::CAIRO,
            'X-Locale'   => 'en',
        ]);
    }

    /**
     * There is no InventoryTransaction factory, and created_at is not fillable, so
     * the row is built directly with its timestamp pinned to an exact UTC instant.
     */
    private function inventoryTransactionAt(string $utc): InventoryTransaction
    {
        $transaction = new InventoryTransaction([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => 1,
            'type'         => InventoryTransaction::TYPE_SALE,
            'user_id'      => $this->user->id,
        ]);

        $transaction->timestamps = false;
        $transaction->created_at = Carbon::parse($utc, 'UTC');
        $transaction->updated_at = Carbon::parse($utc, 'UTC');
        $transaction->save();

        return $transaction;
    }

    // ── 1. Configuration is intentional ──────────────────────────────────────

    public function test_application_timezone_is_utc_and_display_timezone_is_separate(): void
    {
        // Guards against someone reintroducing a local zone into APP_TIMEZONE, which
        // is what made the app generate skewed instants in the first place.
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', now()->getTimezone()->getName());

        // The local zone lives in its own key and is never conflated with app.timezone.
        $this->assertNotEmpty(config('app.display_timezone'));
        $this->assertContains(config('app.display_timezone'), timezone_identifiers_list());
    }

    // ── 2. Serialization carries an explicit UTC marker ──────────────────────

    public function test_audit_log_timestamps_are_serialized_as_iso8601_utc(): void
    {
        $this->inventoryTransactionAt(self::EVENT_UTC);

        $row = $this->asCairoUser()
            ->getJson('/api/audit-log?source=inventory')
            ->assertStatus(200)
            ->json('data.0');

        // Without the trailing Z the browser parses this as local time and renders
        // 19:04 instead of 22:04 — the original user-visible bug.
        //
        // Shape is asserted by pattern rather than exact string because SQLite (the
        // test driver) does not keep sub-second precision, so the fractional digits
        // are environment dependent. What must hold everywhere: ISO-8601, a
        // fractional-seconds field, and an explicit Z.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            $row['created_at']
        );

        $this->assertSame(
            '2026-08-21T19:04:47',
            Carbon::parse($row['created_at'])->utc()->format('Y-m-d\TH:i:s')
        );

        // And it must round-trip back to the right local wall clock.
        $this->assertSame(
            '2026-08-21 22:04:47',
            Carbon::parse($row['created_at'])->setTimezone(self::CAIRO)->format('Y-m-d H:i:s')
        );
    }

    public function test_resource_timestamps_are_serialized_as_iso8601_utc(): void
    {
        Carbon::setTestNow(Carbon::parse(self::EVENT_UTC, 'UTC'));

        $row = $this->asCairoUser()
            ->getJson('/api/customers/' . $this->customer->id)
            ->assertStatus(200)
            ->json('data');

        // CustomerResource previously emitted an unmarked ->toDateTimeString().
        $this->assertStringEndsWith('Z', $row['created_at']);
    }

    // ── 3 & 4. Filtering means the viewer's calendar day ─────────────────────

    public function test_late_evening_event_filters_into_the_correct_local_day(): void
    {
        // 22:04 Cairo on the 21st. In UTC this is still the 21st, so a naive filter
        // happens to work — this is the baseline the next test contrasts with.
        $this->inventoryTransactionAt(self::EVENT_UTC);

        $onTheDay = $this->asCairoUser()
            ->getJson('/api/audit-log?source=inventory&date_from=' . self::EVENT_LOCAL_DATE . '&date_to=' . self::EVENT_LOCAL_DATE)
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $onTheDay);
    }

    public function test_after_midnight_local_event_is_filtered_into_its_local_day_not_the_utc_one(): void
    {
        // THE regression test. 00:30 Cairo on 2026-08-21 is 21:30 UTC on 2026-08-20.
        // Filtering by UTC calendar date buckets this under the 20th — a whole day
        // wrong, which is exactly what put rows under the wrong "YESTERDAY" heading.
        $this->inventoryTransactionAt('2026-08-20 21:30:00');

        $localDay = $this->asCairoUser()
            ->getJson('/api/audit-log?source=inventory&date_from=2026-08-21&date_to=2026-08-21')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $localDay, 'A 00:30 Cairo event belongs to its local day, not the previous UTC one.');

        $previousDay = $this->asCairoUser()
            ->getJson('/api/audit-log?source=inventory&date_from=2026-08-20&date_to=2026-08-20')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(0, $previousDay, 'It must not also appear under the previous local day.');
    }

    public function test_the_audit_boundary_follows_configured_business_timezone_not_the_header(): void
    {
        // Originally this asserted the boundary moved with X-Timezone. It no longer
        // does, deliberately — the feed is a business record. The property still worth
        // proving is that the boundary is not hardcoded to Egypt, so instead of
        // changing the viewer we change the shop.
        $this->inventoryTransactionAt('2026-08-20 21:30:00');   // 00:30 Cairo on the 21st

        // Shop in Cairo: this belongs to the 21st, and a UTC viewer cannot drag it
        // back to the 20th.
        $this->assertCount(1, $this->actingFrom('UTC')
            ->getJson('/api/audit-log?source=inventory&date_from=2026-08-21&date_to=2026-08-21')
            ->assertStatus(200)->json('data'));

        $this->assertCount(0, $this->actingFrom('UTC')
            ->getJson('/api/audit-log?source=inventory&date_from=2026-08-20&date_to=2026-08-20')
            ->assertStatus(200)->json('data'));

        // Move the shop to UTC and the same instant now belongs to the 20th — proving
        // the boundary is read from config rather than baked in.
        config(['app.business_timezone' => 'UTC']);

        $this->assertCount(1, $this->actingFrom(self::CAIRO)
            ->getJson('/api/audit-log?source=inventory&date_from=2026-08-20&date_to=2026-08-20')
            ->assertStatus(200)->json('data'));
    }

    // ── 5. Dashboard "today" is the local day ────────────────────────────────

    public function test_dashboard_today_uses_the_local_calendar_day(): void
    {
        // 00:30 Cairo on the 21st == 21:30 UTC on the 20th. A payment made a few
        // minutes earlier (23:50 Cairo on the 20th) belongs to YESTERDAY locally,
        // even though both fall on the 20th in UTC.
        Carbon::setTestNow(Carbon::parse('2026-08-20 21:30:00', 'UTC'));

        $order = Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'total'       => 500,
        ]);

        Payment::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'order_id'    => $order->id,
            'customer_id' => $this->customer->id,
            'amount'      => 500,
            'method'      => 'cash',
            'paid_at'     => Carbon::parse('2026-08-20 20:50:00', 'UTC'), // 23:50 Cairo, the 20th
        ]);

        $today = $this->asCairoUser()
            ->getJson('/api/dashboard?period=today')
            ->assertStatus(200)
            ->json();

        $revenue = data_get($today, 'period_revenue', data_get($today, 'summary.period_revenue'));

        $this->assertNotEquals(
            500,
            $revenue,
            "Yesterday evening's payment must not count toward today's local revenue."
        );
    }

    // ── 5b. Business day vs viewer day ───────────────────────────────────────
    //
    // The shop trades in Cairo; the owner is in Berlin. Cairo runs an hour ahead, so
    // there is a window each night where the two disagree about the date:
    //
    //     2026-08-21 21:30 UTC  ==  23:30 Berlin on the 21st
    //                           ==  00:30 Cairo  on the 22nd
    //
    // Every business-day question must answer "the 22nd" no matter who is asking.
    // Display of the instant itself still follows the viewer — that is not a bug.

    private const CROSSOVER_UTC = '2026-08-21 21:30:00';
    private const BUSINESS_DAY  = '2026-08-22';   // Cairo
    private const VIEWER_DAY    = '2026-08-21';   // Berlin

    private function actingFrom(string $timezone)
    {
        return $this->actingAs($this->user)->withHeaders([
            'X-Timezone' => $timezone,
            'X-Locale'   => 'en',
        ]);
    }

    private function cashPaymentAtCrossover(float $amount = 400): Order
    {
        $order = Order::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'total'       => $amount,
            'order_date'  => self::BUSINESS_DAY,
        ]);

        Payment::factory()->create([
            'tenant_id'          => $this->tenant->id,
            'order_id'           => $order->id,
            'customer_id'        => $this->customer->id,
            'amount'             => $amount,
            'method'             => 'cash',
            'is_auto_reversible' => false,
            'paid_at'            => Carbon::parse(self::CROSSOVER_UTC, 'UTC'),
        ]);

        return $order;
    }

    public function test_instant_still_displays_in_the_viewers_zone_not_the_shops(): void
    {
        // Display must keep following the viewer. This is the control for every
        // assertion below — if this ever flips, the business-day fix went too far.
        $this->inventoryTransactionAt(self::CROSSOVER_UTC);

        $row = $this->actingFrom('Europe/Berlin')
            ->getJson('/api/audit-log?source=inventory')
            ->assertStatus(200)
            ->json('data.0');

        $this->assertSame(
            '2026-08-21 23:30',
            Carbon::parse($row['created_at'])->setTimezone('Europe/Berlin')->format('Y-m-d H:i'),
            'The Berlin viewer must still see 23:30 on the 21st.'
        );

        $this->assertSame(
            '2026-08-22 00:30',
            Carbon::parse($row['created_at'])->setTimezone(self::CAIRO)->format('Y-m-d H:i'),
            'The same instant is 00:30 on the 22nd in the shop\'s zone.'
        );
    }

    public function test_dashboard_counts_the_sale_in_the_shops_business_day(): void
    {
        // "Now" is the crossover instant, so the shop is on the 22nd and Berlin is
        // still on the 21st. period=today must mean the shop's 22nd.
        Carbon::setTestNow(Carbon::parse(self::CROSSOVER_UTC, 'UTC'));
        $this->cashPaymentAtCrossover(400);

        $stats = $this->actingFrom('Europe/Berlin')
            ->getJson('/api/dashboard?period=today')
            ->assertStatus(200)
            ->json('stats');

        $this->assertEquals(400, $stats['today_revenue'], 'The sale belongs to the shop\'s today.');
        $this->assertEquals(1, $stats['today_payments_count']);
    }

    public function test_dashboard_totals_are_identical_wherever_the_owner_is(): void
    {
        // The whole point: a till is reconciled against one number.
        Carbon::setTestNow(Carbon::parse(self::CROSSOVER_UTC, 'UTC'));
        $this->cashPaymentAtCrossover(400);

        $cairo  = $this->actingFrom(self::CAIRO)->getJson('/api/dashboard?period=today')->assertStatus(200)->json('stats');
        $berlin = $this->actingFrom('Europe/Berlin')->getJson('/api/dashboard?period=today')->assertStatus(200)->json('stats');
        $ny     = $this->actingFrom('America/New_York')->getJson('/api/dashboard?period=today')->assertStatus(200)->json('stats');

        $this->assertSame($cairo, $berlin);
        $this->assertSame($cairo, $ny, 'Even 7 hours behind, the business day is the shop\'s.');
    }

    public function test_today_validation_means_the_shops_day_not_the_viewers(): void
    {
        // Berlin is still on the 21st, but the shop is trading on the 22nd. A sale
        // dated to the shop's today must be accepted from a Berlin device.
        Carbon::setTestNow(Carbon::parse(self::CROSSOVER_UTC, 'UTC'));

        $this->actingFrom('Europe/Berlin')->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => self::BUSINESS_DAY,
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201);
    }

    public function test_audit_log_date_filter_uses_the_shops_calendar(): void
    {
        // Filtering the shop's 22nd finds it; the viewer's 21st does not.
        $this->inventoryTransactionAt(self::CROSSOVER_UTC);

        $onBusinessDay = $this->actingFrom('Europe/Berlin')
            ->getJson('/api/audit-log?source=inventory&date_from=' . self::BUSINESS_DAY . '&date_to=' . self::BUSINESS_DAY)
            ->assertStatus(200)->json('data');

        $this->assertCount(1, $onBusinessDay);

        $onViewerDay = $this->actingFrom('Europe/Berlin')
            ->getJson('/api/audit-log?source=inventory&date_from=' . self::VIEWER_DAY . '&date_to=' . self::VIEWER_DAY)
            ->assertStatus(200)->json('data');

        $this->assertCount(0, $onViewerDay, 'The viewer\'s calendar must not move the business boundary.');
    }

    public function test_last_purchased_at_stores_the_shops_calendar_date(): void
    {
        // last_purchased_at is a DATE column. At the crossover the shop is on the
        // 22nd while UTC is still the 21st — it must record the shop's date.
        Carbon::setTestNow(Carbon::parse(self::CROSSOVER_UTC, 'UTC'));

        $supplier = \App\Models\Supplier::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingFrom('Europe/Berlin')->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 10, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201);

        $this->assertDatabaseHas('supplier_products', [
            'supplier_id'       => $supplier->id,
            'product_id'        => $this->product->id,
            'last_purchased_at' => self::BUSINESS_DAY,
        ]);
    }

    // ── 5c. Invoice numbering across the business New Year ───────────────────
    //
    // 2026-12-31 23:30 UTC is already 2027-01-01 02:30 in Cairo. The invoice year is
    // the shop's year, so the first sale of the Egyptian new year must be numbered
    // 2027 even though UTC is still in 2026.

    private const NEW_YEAR_UTC = '2026-12-31 23:30:00';   // 02:30 Cairo on 1 Jan 2027

    private function createOrderViaApi(): string
    {
        return $this->asCairoUser()->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString(),
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201)->json('invoice_number');
    }

    private function createPurchaseOrderViaApi(int $supplierId): string
    {
        return $this->asCairoUser()->postJson('/api/purchase-orders', [
            'supplier_id' => $supplierId,
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 10, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201)->json('data.invoice_number');
    }

    public function test_order_invoice_uses_the_business_year_across_new_year(): void
    {
        Carbon::setTestNow(Carbon::parse(self::NEW_YEAR_UTC, 'UTC'));

        $first = $this->createOrderViaApi();
        $this->assertSame('2027-001', $first, 'UTC is still 2026, but the shop is in 2027.');

        // The second sale must find the first, proving the lookup agrees with the label.
        $second = $this->createOrderViaApi();
        $this->assertSame('2027-002', $second);
    }

    public function test_order_invoice_numbering_is_unchanged_on_an_ordinary_date(): void
    {
        Carbon::setTestNow(Carbon::parse(self::EVENT_UTC, 'UTC'));   // 22:04 Cairo, 21 Aug

        $this->assertSame('2026-001', $this->createOrderViaApi());
        $this->assertSame('2026-002', $this->createOrderViaApi());
    }

    public function test_purchase_order_invoice_uses_the_business_year_across_new_year(): void
    {
        // This is the one that needed a UTC range: the lookup is on created_at, so a
        // whereYear() in UTC would not see the PO issued minutes earlier under 2027.
        Carbon::setTestNow(Carbon::parse(self::NEW_YEAR_UTC, 'UTC'));

        $supplier = \App\Models\Supplier::factory()->create(['tenant_id' => $this->tenant->id]);

        $first = $this->createPurchaseOrderViaApi($supplier->id);
        $this->assertSame('2027-001', $first);

        $second = $this->createPurchaseOrderViaApi($supplier->id);
        $this->assertSame('2027-002', $second, 'The lookup must find the 2027 PO created moments before.');
    }

    public function test_purchase_order_invoice_numbering_is_unchanged_on_an_ordinary_date(): void
    {
        Carbon::setTestNow(Carbon::parse(self::EVENT_UTC, 'UTC'));

        $supplier = \App\Models\Supplier::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->assertSame('2026-001', $this->createPurchaseOrderViaApi($supplier->id));
        $this->assertSame('2026-002', $this->createPurchaseOrderViaApi($supplier->id));
    }

    // ── 6. "today" validation respects the shop's zone ───────────────────────

    public function test_order_date_of_local_today_is_accepted_just_after_local_midnight(): void
    {
        // 00:30 Cairo on the 21st. In UTC it is still the 20th, so a UTC-resolved
        // before_or_equal:today rejects the 21st as a future date — refusing a
        // legitimate sale for the first three hours of every local morning.
        Carbon::setTestNow(Carbon::parse('2026-08-20 21:30:00', 'UTC'));

        $this->asCairoUser()->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => '2026-08-21',
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201);
    }

    public function test_order_date_in_the_local_future_is_still_rejected(): void
    {
        // The relaxation above must not become "any future date is fine".
        Carbon::setTestNow(Carbon::parse('2026-08-20 21:30:00', 'UTC'));

        $this->asCairoUser()->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => '2026-08-25',
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('order_date');
    }

    // ── 7. Middleware falls back safely ──────────────────────────────────────

    public function test_missing_or_invalid_timezone_header_falls_back_to_the_configured_default(): void
    {
        $this->inventoryTransactionAt(self::EVENT_UTC);

        foreach ([null, 'Not/AZone', '+03:00', ''] as $header) {
            $response = $this->actingAs($this->user)
                ->withHeaders($header === null ? [] : ['X-Timezone' => $header])
                ->getJson('/api/audit-log?source=inventory');

            $response->assertStatus(200);
            $this->assertStringEndsWith('Z', $response->json('data.0.created_at'));
        }
    }

    // ── 8. order_date persists; created_at stays the real instant ────────────

    public function test_backdated_order_keeps_its_business_date_and_a_real_created_at(): void
    {
        // Regression: createOrderAttempt used to omit order_date from Order::create()
        // and assign the request's date to created_at instead. That lost the chosen
        // business date (order_date fell back to its DB default of today) AND flattened
        // created_at to midnight, so an order disagreed with the ledger entry written
        // beside it in the same transaction.
        Carbon::setTestNow(Carbon::parse(self::EVENT_UTC, 'UTC')); // 22:04 Cairo on the 21st

        $orderId = $this->asCairoUser()->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => '2026-08-18',           // backdated by three days
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201)->json('id');

        $order = Order::findOrFail($orderId);

        // The business date the user chose is stored, not today's date.
        $this->assertSame('2026-08-18', $order->order_date instanceof Carbon
            ? $order->order_date->toDateString()
            : substr((string) $order->order_date, 0, 10));

        // created_at is the real creation instant, not midnight of the business date.
        $this->assertSame('2026-08-21 19:04:47', $order->created_at->utc()->format('Y-m-d H:i:s'));

        // And it agrees with the ORDER_CHARGE ledger row written in the same transaction.
        $charge = \App\Models\LedgerEntry::where('type', 'ORDER_CHARGE')
            ->where('reference_id', $orderId)
            ->firstOrFail();

        $this->assertSame(
            $order->created_at->utc()->toDateString(),
            $charge->created_at->utc()->toDateString()
        );
    }

    public function test_orders_list_filters_by_business_date_not_entry_date(): void
    {
        // The order was entered on the 21st but dated the 18th. Filtering the list for
        // the 18th must find it — that is what "show me the 18th" means to the shop.
        Carbon::setTestNow(Carbon::parse(self::EVENT_UTC, 'UTC'));

        $this->asCairoUser()->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => '2026-08-18',
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201);

        $onBusinessDate = $this->asCairoUser()
            ->getJson('/api/orders?date_from=2026-08-18&date_to=2026-08-18')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $onBusinessDate);

        $onEntryDate = $this->asCairoUser()
            ->getJson('/api/orders?date_from=2026-08-21&date_to=2026-08-21')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(0, $onEntryDate, 'The list filters on order_date, not created_at.');
    }

    // ── 9. Calendar DATE columns are never zone-shifted ──────────────────────

    public function test_calendar_date_columns_are_identical_regardless_of_viewer_timezone(): void
    {
        // order_date is a DATE column with no instant behind it. Applying a zone
        // conversion to it would shift it by a whole day. Created through the real
        // endpoint so the value travels the same path a user's order would.
        Carbon::setTestNow(Carbon::parse(self::EVENT_UTC, 'UTC'));

        $orderId = $this->asCairoUser()->postJson('/api/orders', [
            'store_id'    => $this->store->id,
            'customer_id' => $this->customer->id,
            'order_date'  => '2026-08-21',
            'items'       => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'warehouse_id' => $this->warehouse->id],
            ],
        ])->assertStatus(201)->json('id');

        // OrderResource sets $wrap = null, so its fields sit at the response root.
        $cairo = $this->asCairoUser()
            ->getJson('/api/orders/' . $orderId)
            ->assertStatus(200)
            ->json('order_date');

        $utc = $this->actingAs($this->user)
            ->withHeaders(['X-Timezone' => 'UTC'])
            ->getJson('/api/orders/' . $orderId)
            ->assertStatus(200)
            ->json('order_date');

        $this->assertSame($cairo, $utc);
        $this->assertStringStartsWith('2026-08-21', (string) $cairo);
    }
}
