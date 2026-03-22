<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inventory>
 */
class InventoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'product_id' => Product::factory(),
            'quantity' => $this->faker->numberBetween(10, 100),
            'threshold' => 5,
        ];
    }
}
