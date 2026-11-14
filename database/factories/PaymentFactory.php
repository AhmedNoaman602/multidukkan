<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state: a cash payment.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'method'             => 'cash',
            'counts_as_cash'     => true,
            'is_auto_reversible' => false,
        ];
    }

    public function credit(): static
    {
        return $this->state(fn () => [
            'method'             => 'credit',
            'counts_as_cash'     => false,
            'is_auto_reversible' => true,
        ]);
    }

    public function returnOffset(): static
    {
        return $this->state(fn () => [
            'method'             => 'return_offset',
            'counts_as_cash'     => false,
            'is_auto_reversible' => false,
        ]);
    }
}
