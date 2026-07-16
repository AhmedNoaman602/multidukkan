<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PurchaseOrderItem>
 */
class PurchaseOrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity  = $this->faker->numberBetween(1, 100);
        $unitPrice = $this->faker->numberBetween(1, 100);

        return [
            'purchase_order_id' => null,
            'product_id'        => null,
            'warehouse_id'      => null,
            'quantity'          => $quantity,
            'unit_price'        => $unitPrice,
            'total'             => $quantity * $unitPrice,
        ];
    }
}
