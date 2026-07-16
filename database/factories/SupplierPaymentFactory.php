<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SupplierPayment>
 */
class SupplierPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'         => null,
            'purchase_order_id' => null,
            'supplier_id'       => null,
            'amount'            => $this->faker->numberBetween(1, 500),
            'method'            => 'cash',
            'paid_at'           => now(),
        ];
    }
}
