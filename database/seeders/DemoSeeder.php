<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use App\Models\Store;
use App\Models\User;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::create([
            'name' => 'Abu Ahmed Store',
        ]);

        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Main Store',
            'address'   => 'Cairo, Egypt',
            'phone'     => '01000000000',
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'store_id'  => null,
            'name'      => 'Ahmed',
            'email'     => 'ahmed@multidukkan.com',
            'password'  => bcrypt('password123'),
            'role'      => 'tenant_admin',
        ]);
    }
}