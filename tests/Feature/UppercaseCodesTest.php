<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Product SKUs and customer/supplier codes are stored in capitals, whatever the user typed.
class UppercaseCodesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->admin  = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
    }

    // ── Products ─────────────────────────────────────────────────

    public function test_a_new_product_sku_is_saved_in_capitals(): void
    {
        $this->actingAs($this->admin)->postJson('/api/products', [
            'name' => 'Nails', 'sku' => 'tn-1000a', 'price' => 10,
        ])->assertStatus(201)->assertJsonPath('data.sku', 'TN-1000A');

        $this->assertDatabaseHas('products', ['tenant_id' => $this->tenant->id, 'sku' => 'TN-1000A']);
    }

    public function test_an_edited_product_sku_is_saved_in_capitals(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'sku' => 'OLD-1']);

        $this->actingAs($this->admin)->putJson("/api/products/{$product->id}", [
            'name' => $product->name, 'sku' => 'hm-200', 'price' => 10,
        ])->assertOk();

        $this->assertSame('HM-200', $product->fresh()->sku);
    }

    public function test_a_sku_that_differs_only_in_letter_case_counts_as_taken(): void
    {
        Product::factory()->create(['tenant_id' => $this->tenant->id, 'sku' => 'TN-1000']);

        $this->actingAs($this->admin)->postJson('/api/products', [
            'name' => 'Nails again', 'sku' => 'tn-1000', 'price' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors('sku');

        $this->assertSame(1, Product::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_arabic_letters_and_digits_in_a_sku_are_left_alone(): void
    {
        $this->actingAs($this->admin)->postJson('/api/products', [
            'name' => 'مسامير', 'sku' => 'مسمار-ab12', 'price' => 10,
        ])->assertStatus(201);

        $this->assertDatabaseHas('products', ['tenant_id' => $this->tenant->id, 'sku' => 'مسمار-AB12']);
    }

    // ── Customers ────────────────────────────────────────────────

    public function test_a_typed_customer_code_is_saved_in_capitals(): void
    {
        $this->actingAs($this->admin)->postJson('/api/customers', [
            'name' => 'Ahmed', 'phone' => '01111111111', 'code' => 'vip-7',
        ])->assertStatus(201);

        $this->assertDatabaseHas('customers', ['tenant_id' => $this->tenant->id, 'code' => 'VIP-7']);

        $customer = Customer::where('code', 'VIP-7')->firstOrFail();
        $this->actingAs($this->admin)->putJson("/api/customers/{$customer->id}", [
            'name' => 'Ahmed', 'phone' => '01111111111', 'code' => 'vip-8',
        ])->assertOk();

        $this->assertSame('VIP-8', $customer->fresh()->code);
    }

    public function test_a_customer_without_a_code_still_gets_a_generated_one(): void
    {
        $this->actingAs($this->admin)->postJson('/api/customers', [
            'name' => 'Mona', 'phone' => '01222222222',
        ])->assertStatus(201);

        $this->assertMatchesRegularExpression('/^C-\d{3}$/', Customer::where('name', 'Mona')->value('code'));
    }

    // ── Suppliers ────────────────────────────────────────────────

    public function test_a_typed_supplier_code_is_saved_in_capitals(): void
    {
        $this->actingAs($this->admin)->postJson('/api/suppliers', [
            'name' => 'Cairo Tools', 'phone' => '01333333333', 'code' => 'ct-1',
        ])->assertStatus(201);

        $supplier = Supplier::where('tenant_id', $this->tenant->id)->where('name', 'Cairo Tools')->firstOrFail();
        $this->assertSame('CT-1', $supplier->code);

        $this->actingAs($this->admin)->putJson("/api/suppliers/{$supplier->id}", [
            'name' => 'Cairo Tools', 'phone' => '01333333333', 'code' => 'ct-2',
        ])->assertOk();

        $this->assertSame('CT-2', $supplier->fresh()->code);
    }

    public function test_a_supplier_without_a_code_still_gets_a_generated_one(): void
    {
        $this->actingAs($this->admin)->postJson('/api/suppliers', [
            'name' => 'Delta Hardware', 'phone' => '01444444444',
        ])->assertStatus(201);

        $this->assertMatchesRegularExpression('/^S-\d{3}$/', Supplier::where('name', 'Delta Hardware')->value('code'));
    }
}
