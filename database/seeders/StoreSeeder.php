<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Store;
use App\Models\Tenant;
class StoreSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
       $tenants = Tenant::all();
       foreach ($tenants as $tenant) {
           Store::factory()->count(2)->create([
               'tenant_id' => $tenant->id,
           ]);
       }
    }
}
