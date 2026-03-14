<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => null,
            'product_name' => $this->faker->name,
            'product_id' => null,
            'quantity' => $this->faker->numberBetween(1, 100),
            'unit_price' => $this->faker->numberBetween(1, 100),
        ];
    }
}
