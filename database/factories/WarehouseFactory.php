<?php

namespace Database\Factories;

use App\Models\Warehouse;
use App\Models\Tenant;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
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
            'store_id' => Store::factory(),
            'name' => $this->faker->word() . ' Warehouse',
            'address' => $this->faker->address(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->email(),
        ];
    }

    
}
