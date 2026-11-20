<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemCost;
use App\Models\Product;
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

    // A factory line stands for a sale, so it gets the allocation a sale would save:
    // the product's cost at creation for the line's base quantity (unknown if the cost is empty).
    public function configure(): static
    {
        return $this->afterCreating(function (OrderItem $item) {
            $cost = Product::withoutGlobalScopes()->whereKey($item->product_id)->value('cost_price');

            $item->costs()->create([
                'tenant_id'        => Order::withoutGlobalScopes()->whereKey($item->order_id)->value('tenant_id'),
                'base_quantity'    => $item->baseQuantity(),
                'unit_cost'        => $cost,
                'unit_cost_source' => $cost === null ? null : OrderItemCost::SOURCE_AVERAGE_AT_SALE,
            ]);
        });
    }
}
