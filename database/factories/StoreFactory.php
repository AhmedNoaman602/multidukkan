<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Warehouse;
/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Store>
 */
class StoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'name' => $this->faker->name,
            'address' => $this->faker->address,
            'phone' => $this->faker->phoneNumber,
        ];
    }

    public function withShelf(): static
    {
        return $this->afterCreating(fn (Store $store) => Warehouse::factory()->shelf()->create([
            'tenant_id' => $store->tenant_id,
            'store_id'  => $store->id,
        ]));
    }
}
