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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

// API error messages follow the X-Locale header and never expose class names or internals.
class LocalizedErrorResponsesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private Customer $customer;
    private Product $product;
    private User $admin;
    private User $staff;

    private const AR = ['X-Locale' => 'ar', 'Accept' => 'application/json'];
    private const EN = ['X-Locale' => 'en', 'Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::factory()->create();
        $this->store    = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product  = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 100]);
        $this->admin    = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => null, 'role' => 'tenant_admin']);
        $this->staff    = User::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'role' => 'store_staff']);

        $shelf = Warehouse::factory()->shelf()->create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id]);
        Inventory::factory()->create([
            'tenant_id' => $this->tenant->id, 'warehouse_id' => $shelf->id, 'product_id' => $this->product->id, 'quantity' => 100,
        ]);
    }

    private function orderOfOne(): Order
    {
        $this->actingAs($this->admin)->postJson('/api/orders', [
            'store_id' => $this->store->id, 'customer_id' => $this->customer->id, 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        return Order::latest('id')->firstOrFail();
    }

    private function invalidOrder(): array
    {
        // Two errors: a missing customer and a fractional nested quantity.
        return ['store_id' => $this->store->id, 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1.5]]];
    }

    // ── Validation ───────────────────────────────────────────────

    public function test_arabic_validation_messages_name_fields_in_arabic_including_nested_ones(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/orders', $this->invalidOrder(), self::AR)->assertStatus(422);

        $this->assertSame('حقل العميل مطلوب.', $response->json('errors.customer_id.0'));
        $this->assertSame('يجب أن يكون الكمية عددًا صحيحًا.', $response->json('errors')['items.0.quantity'][0]);
        $this->assertSame('حقل العميل مطلوب. (وخطأ آخر)', $response->json('message'));

        // Field paths stay as the keys of "errors" (the screen uses them); no message text shows them.
        $texts = implode(' ', [$response->json('message'), ...array_merge(...array_values($response->json('errors')))]);
        $this->assertStringNotContainsString('items.0.quantity', $texts);
        $this->assertStringNotContainsString('customer id', $texts);
    }

    public function test_arabic_summary_counts_several_extra_errors(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/orders', [], self::AR)->assertStatus(422);

        $this->assertMatchesRegularExpression('/\(و\d+ أخطاء أخرى\)$/u', $response->json('message'));
    }

    // Arabic has six plural forms: 1 and 2 have their own words, 3–10 take the plural,
    // 11–99 the accusative singular, and 100+ the singular.
    public function test_the_extra_errors_suffix_uses_the_right_arabic_plural_form(): void
    {
        $expected = [
            1   => '(وخطأ آخر)',
            2   => '(وخطآن آخران)',
            3   => '(و3 أخطاء أخرى)',
            10  => '(و10 أخطاء أخرى)',
            11  => '(و11 خطأً آخر)',
            99  => '(و99 خطأً آخر)',
            100 => '(و100 خطأ آخر)',
            103 => '(و103 أخطاء أخرى)',
        ];

        foreach ($expected as $extra => $suffix) {
            $this->assertStringEndsWith(' ' . $suffix, $this->summary('ar', $extra), "{$extra} extra errors");
        }

        $this->assertStringEndsWith(' (and 1 more error)', $this->summary('en', 1));
        $this->assertStringEndsWith(' (and 2 more errors)', $this->summary('en', 2));
    }

    // The message Laravel puts in a 422 response for 1 + $extra failing fields.
    private function summary(string $locale, int $extra): string
    {
        app()->setLocale($locale);
        $rules = [];
        for ($i = 0; $i <= $extra; $i++) {
            $rules["field_{$i}"] = 'required';
        }

        try {
            Validator::make([], $rules)->validate();
        } catch (ValidationException $e) {
            return $e->getMessage();
        }

        $this->fail('Validation should have failed.');
    }

    public function test_english_validation_messages_are_unchanged(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/orders', $this->invalidOrder(), self::EN)->assertStatus(422);

        $this->assertSame('The customer id field is required.', $response->json('errors.customer_id.0'));
        $this->assertSame('The items.0.quantity field must be an integer.', $response->json('errors')['items.0.quantity'][0]);
        $this->assertSame('The customer id field is required. (and 1 more error)', $response->json('message'));
    }

    public function test_the_order_item_update_rule_message_is_translated(): void
    {
        $order  = $this->orderOfOne();
        $itemId = DB::table('order_items')->where('order_id', $order->id)->value('id');

        $this->actingAs($this->admin)->patchJson("/api/orders/{$order->id}/items/{$itemId}", [], self::AR)
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'اكتب الكمية أو سعر الوحدة عشان تعدّل الصنف.');

        $this->actingAs($this->admin)->patchJson("/api/orders/{$order->id}/items/{$itemId}", [], self::EN)
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Provide quantity or unit_price to update.');
    }

    // ── Missing resources ────────────────────────────────────────

    public function test_a_missing_record_returns_a_localized_404_without_internals(): void
    {
        $ar = $this->actingAs($this->admin)->getJson('/api/orders/999999', self::AR)
            ->assertStatus(404)
            ->assertExactJson(['message' => 'العنصر المطلوب غير موجود أو تم حذفه.']);
        $this->assertStringNotContainsString('App\\', $ar->getContent());
        $this->assertStringNotContainsString('No query results', $ar->getContent());

        $this->actingAs($this->admin)->getJson('/api/orders/999999', self::EN)
            ->assertStatus(404)
            ->assertExactJson(['message' => 'The requested item was not found or has been deleted.']);
    }

    public function test_a_record_from_another_tenant_looks_missing_too(): void
    {
        $other = Tenant::factory()->create();
        $foreign = Product::factory()->create(['tenant_id' => $other->id]);

        $this->actingAs($this->admin)->getJson("/api/products/{$foreign->id}", self::AR)
            ->assertStatus(404)
            ->assertExactJson(['message' => 'العنصر المطلوب غير موجود أو تم حذفه.']);
    }

    // ── Unknown routes ───────────────────────────────────────────

    public function test_an_unknown_route_returns_a_localized_404(): void
    {
        $this->actingAs($this->admin)->getJson('/api/no-such-page', self::AR)
            ->assertStatus(404)
            ->assertExactJson(['message' => 'الصفحة أو الإجراء المطلوب غير موجود.']);

        $this->actingAs($this->admin)->getJson('/api/no-such-page', self::EN)
            ->assertStatus(404)
            ->assertExactJson(['message' => 'The requested page or action does not exist.']);
    }

    public function test_a_wrong_http_method_keeps_its_405_status(): void
    {
        $this->deleteJson('/api/login', [], self::AR)
            ->assertStatus(405)
            ->assertHeader('Allow')
            ->assertExactJson(['message' => 'الصفحة أو الإجراء المطلوب غير موجود.']);
    }

    // ── Unauthorized / unauthenticated ──────────────────────────

    public function test_a_denied_action_returns_a_localized_403(): void
    {
        $this->actingAs($this->staff)->deleteJson("/api/products/{$this->product->id}", [], self::AR)
            ->assertStatus(403)
            ->assertExactJson(['message' => 'غير مصرح لك بهذا الإجراء']);

        $this->actingAs($this->staff)->deleteJson("/api/products/{$this->product->id}", [], self::EN)
            ->assertStatus(403)
            ->assertExactJson(['message' => 'You are not authorized to perform this action']);

        $this->assertDatabaseHas('products', ['id' => $this->product->id]);
    }

    public function test_a_guest_gets_a_localized_401(): void
    {
        $this->getJson('/api/orders', self::AR)
            ->assertStatus(401)
            ->assertExactJson(['message' => 'غير مصرح بالدخول']);
    }

    public function test_a_message_the_app_passes_to_abort_is_kept(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('/api/_test/abort', fn () => abort(403, __('messages.unauthorized_delete_user')));

        $this->getJson('/api/_test/abort', self::AR)
            ->assertStatus(403)
            ->assertExactJson(['message' => 'غير مصرح لك بحذف هذا المستخدم']);
    }

    // ── Rate limits ──────────────────────────────────────────────

    public function test_login_rate_limit_returns_a_localized_429_with_retry_after(): void
    {
        $attempt = fn () => $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'], self::AR);

        for ($i = 0; $i < 5; $i++) {
            $attempt()->assertStatus(401);
        }

        $response = $attempt()->assertStatus(429)->assertHeader('Retry-After');
        $seconds  = (int) $response->headers->get('Retry-After');

        app()->setLocale('ar');
        $this->assertSame(trans_choice('auth.throttle', $seconds, ['seconds' => $seconds]), $response->json('message'));
        $this->assertStringContainsString('محاولات تسجيل دخول كثيرة جدًا', $response->json('message'));
    }

    public function test_the_wait_time_uses_the_right_arabic_plural_form(): void
    {
        app()->setLocale('ar');
        $wait = fn (string $key, int $seconds) => trans_choice($key, $seconds, ['seconds' => $seconds]);

        $this->assertStringEndsWith('بعد ثانية واحدة.', $wait('auth.throttle', 1));
        $this->assertStringEndsWith('بعد ثانيتين.', $wait('auth.throttle', 2));
        $this->assertStringEndsWith('بعد 5 ثوانٍ.', $wait('auth.throttle', 5));
        $this->assertStringEndsWith('بعد 10 ثوانٍ.', $wait('auth.throttle', 10));
        $this->assertStringEndsWith('بعد 37 ثانية.', $wait('auth.throttle', 37));
        $this->assertStringEndsWith('بعد 60 ثانية.', $wait('messages.too_many_requests', 60));
        $this->assertStringEndsWith('بعد 3 ثوانٍ.', $wait('messages.too_many_requests', 3));

        app()->setLocale('en');
        $this->assertSame('Too many login attempts. Please try again in 37 seconds.', $wait('auth.throttle', 37));
        $this->assertSame('Too many requests. Please try again in 1 second.', $wait('messages.too_many_requests', 1));
        $this->assertSame('Too many requests. Please try again in 37 seconds.', $wait('messages.too_many_requests', 37));
    }

    // ── Unexpected server errors ────────────────────────────────

    public function test_an_unexpected_error_is_generic_for_the_user_and_still_reported(): void
    {
        config(['app.debug' => false]);
        Exceptions::fake();
        Route::middleware('api')->get('/api/_test/boom', fn () => throw new RuntimeException('SQLSTATE secret table orders_internal'));

        $response = $this->getJson('/api/_test/boom', self::AR)
            ->assertStatus(500)
            ->assertExactJson(['message' => 'حدث خطأ غير متوقع. حاول مرة أخرى، ولو تكررت المشكلة تواصل مع الدعم.']);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', $response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'SQLSTATE secret table orders_internal');
    }

    public function test_an_unexpected_error_keeps_full_details_while_debugging_locally(): void
    {
        config(['app.debug' => true]);
        Route::middleware('api')->get('/api/_test/boom', fn () => throw new RuntimeException('debug detail'));

        $this->getJson('/api/_test/boom', self::AR)
            ->assertStatus(500)
            ->assertJsonPath('message', 'debug detail')
            ->assertJsonStructure(['message', 'exception', 'file', 'line', 'trace']);
    }
}
