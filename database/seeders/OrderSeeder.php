<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Store;
use App\Models\Order;
use App\Models\Customer;
use App\Models\User;
class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stores = Store::all();
        $customers = Customer::all();
        $users = User::all();
        foreach ($stores as $store) {
            Order::factory()->count(3)->create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'customer_id' => $customers->random()->id,
                'created_by' => $users->random()->id,
            ]);
        }
    }
}
