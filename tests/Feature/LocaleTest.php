<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Warehouse $warehouse;
    protected Product $product;
    protected Inventory $inventory;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->store = Store::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->warehouse = Warehouse::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => $this->store->id,
        ]);
        $this->inventory = Inventory::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => 10,
            'threshold'    => 5,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id'  => null,
            'role'      => 'tenant_admin',
        ]);
    }

    private function attemptOverRemoval(?string $locale): \Illuminate\Testing\TestResponse
    {
        $request = $this->actingAs($this->user);

        if ($locale !== null) {
            $request = $request->withHeaders(['X-Locale' => $locale]);
        }

        return $request->postJson("/api/inventory/{$this->inventory->id}/adjust", [
            'quantity'  => 50,
            'direction' => 'out',
            'notes'     => 'test adjustment',
        ]);
    }

    public function test_english_locale_returns_english_message(): void
    {
        $response = $this->attemptOverRemoval('en');

        $response->assertStatus(422)
            ->assertJson(['message' => 'Cannot remove 50. Only 10 available']);
    }

    public function test_arabic_locale_returns_arabic_message(): void
    {
        $response = $this->attemptOverRemoval('ar');

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن إزالة 50. المتاح فقط: 10']);
    }

    public function test_missing_locale_header_defaults_to_arabic(): void
    {
        $response = $this->attemptOverRemoval(null);

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن إزالة 50. المتاح فقط: 10']);
    }

    public function test_invalid_locale_header_defaults_to_arabic(): void
    {
        $response = $this->attemptOverRemoval('fr');

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن إزالة 50. المتاح فقط: 10']);
    }

    public function test_placeholders_are_substituted_with_real_values(): void
    {
        $this->inventory->update(['quantity' => 7]);

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Locale' => 'en'])
            ->postJson("/api/inventory/{$this->inventory->id}/adjust", [
                'quantity'  => 20,
                'direction' => 'out',
                'notes'     => 'test adjustment',
            ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Cannot remove 20. Only 7 available']);
    }

    public function test_ai_insights_no_data_message_is_localized(): void
    {
        $en = $this->actingAs($this->user)
            ->withHeaders(['X-Locale' => 'en'])
            ->getJson('/api/ai/insights');
        $en->assertStatus(200)
            ->assertJson(['no_data' => true, 'message' => 'Not enough sales data in the last 30 days.']);

        $ar = $this->actingAs($this->user)
            ->withHeaders(['X-Locale' => 'ar'])
            ->getJson('/api/ai/insights');
        $ar->assertStatus(200)
            ->assertJson(['no_data' => true, 'message' => 'لا توجد بيانات مبيعات كافية في آخر 30 يوم.']);
    }

    public function test_arabic_validation_error_uses_arabic_validation_file(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Locale' => 'ar'])
            ->postJson('/api/units', []);

        $response->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'حقل name مطلوب.');
    }

    public function test_english_validation_error_uses_english_validation_file(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Locale' => 'en'])
            ->postJson('/api/units', []);

        $response->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'The name field is required.');
    }

    public function test_inventory_transaction_type_is_unaffected_by_locale(): void
    {
        $this->actingAs($this->user)
            ->withHeaders(['X-Locale' => 'ar'])
            ->postJson("/api/inventory/{$this->inventory->id}/adjust", [
                'quantity'  => 3,
                'direction' => 'in',
                'notes'     => 'test adjustment',
            ])->assertStatus(200);

        $this->assertDatabaseHas('inventory_transactions', [
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $this->product->id,
            'quantity'     => 3,
            'type'         => 'ADJUSTMENT_IN',
        ]);
    }
}
