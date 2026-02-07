<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\Balance;
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Use seeders or tinker instead of factories
        // $this->call([
        //     BalanceSeeder::class,
        // ]);
    }
}
