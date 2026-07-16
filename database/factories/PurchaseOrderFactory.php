<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'   => null,
            'supplier_id' => null,
            'invoice_number' => now()->year . '-' . $this->faker->unique()->numberBetween(1, 999999),
            'total'       => 0,
            'notes'       => $this->faker->sentence(),
        ];
    }
}
