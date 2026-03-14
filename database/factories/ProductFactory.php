<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
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
            'sku' => $this->faker->unique()->numberBetween(1, 100),
            'price' => $this->faker->numberBetween(1, 100),
            'unit' => $this->faker->randomElement(['unit', 'kg', 'g', 'ml', 'l']),
        ];
    }
}
