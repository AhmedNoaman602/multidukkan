<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;

class ProductTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;

public function test_can_update_product_without_changing_sku(): void
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => 'tenant_admin',
        'store_id' => null,
    ]);
    $product = Product::factory()->create([
        'tenant_id' => $tenant->id,
        'sku' => 'TEST-001',
    ]);

    $response = $this->actingAs($user)->putJson("/api/products/{$product->id}", [
        'name' => 'Updated Name',
        'sku' => 'TEST-001',
        'price' => 100,
        'unit' => 'حبة',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Updated Name',
        'sku' => 'TEST-001',
    ]);
}
}
