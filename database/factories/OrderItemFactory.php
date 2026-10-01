<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Warehouse;
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
            'warehouse_id' => function (array $attributes) {
                $order = Order::withoutGlobalScopes()->findOrFail($attributes['order_id']);

                return Warehouse::factory()->create([
                    'tenant_id' => $order->tenant_id,
                    'store_id'  => $order->store_id,
                ])->id;
            },
            'quantity' => $this->faker->numberBetween(1, 100),
            'unit_type' => 'base',
            'conversion_factor' => 1,
            'unit_name' => 'pcs',
            'unit_price' => $this->faker->numberBetween(1, 100),
        ];
    }
}
