<?php

namespace Database\Factories;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
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
            'store_id'  => null,
            'created_by' => null,
            'category' => 'RENT',
            'amount' => $this->faker->randomFloat(2, 100, 5000),
            'description' => $this->faker->sentence(),
            'expense_date' => now(),

        ];
    }
}
