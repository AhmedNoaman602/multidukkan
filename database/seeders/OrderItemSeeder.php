<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\OrderItem;
class OrderItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Order::all()->each(function ($order) {
            OrderItem::factory()->count(2)->create([
                'order_id' => $order->id,
                'product_id' => $order->product_id,
            ]);
        });
    }
}
