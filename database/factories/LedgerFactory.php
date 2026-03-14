<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Ledger>
 */
class LedgerFactory extends Factory
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
            'customer_id' => null,
            'store_id' => null,
            'type' => $this->faker->randomElement(LedgerEntry::TYPES),
            'amount' => $this->faker->numberBetween(1, 100),
            'description' => $this->faker->sentence,
            'reference_id' => null,
            'reference_type' => null,
        ];
    }
}
