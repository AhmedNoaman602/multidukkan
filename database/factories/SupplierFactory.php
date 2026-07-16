<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Supplier>
 */
class SupplierFactory extends Factory
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
            'name'      => $this->faker->company(),
            'phone'     => $this->faker->phoneNumber(),
            'email'     => $this->faker->companyEmail(),
            'address'   => $this->faker->address(),
        ];
    }
}
