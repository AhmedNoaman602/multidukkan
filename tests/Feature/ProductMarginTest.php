<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMarginTest extends TestCase
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

    private function showProduct(array $attributes): array
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, ...$attributes]);

        return $this->actingAs($this->admin)
            ->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->json('data');
    }

    public function test_margin_is_a_percentage_of_the_selling_price(): void
    {
        // 150 − 130 = 20 profit; 20 / 150 = 13.3%, not 20
        $product = $this->showProduct(['sku' => '158', 'name' => 'mosmar', 'price' => 150, 'cost_price' => 130]);

        $this->assertSame(13.3, (float) $product['profit_margin']);
    }

    public function test_tier_margin_uses_its_own_price_and_falls_back_to_the_default_price(): void
    {
        $product = $this->showProduct(['price' => 150, 'price_a' => 200, 'price_b' => null, 'cost_price' => 130]);

        $this->assertSame(35.0, (float) $product['profit_margin_a']);
        $this->assertSame(13.3, (float) $product['profit_margin_b']);
    }

    public function test_selling_below_cost_gives_a_negative_margin(): void
    {
        // 80 − 100 = −20 profit; −20 / 80 = −25%
        $product = $this->showProduct(['price' => 80, 'cost_price' => 100]);

        $this->assertSame(-25.0, (float) $product['profit_margin']);
    }

    public function test_missing_cost_gives_no_margin(): void
    {
        $product = $this->showProduct(['price' => 150, 'cost_price' => null]);

        $this->assertNull($product['profit_margin']);
        $this->assertNull($product['profit_margin_a']);
    }

    public function test_zero_selling_price_gives_zero_margin(): void
    {
        $product = $this->showProduct(['price' => 0, 'cost_price' => 130]);

        $this->assertSame(0.0, (float) $product['profit_margin']);
    }
}
