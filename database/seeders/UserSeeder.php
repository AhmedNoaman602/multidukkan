<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stores = Store::all();
        $tenants = Tenant::all();
        foreach ($stores as $store) {
            User::factory()->count(3)->create([
                'tenant_id' => $tenants->random()->id,
                'store_id' => $store->id,
            ]);
        }
    }
}
