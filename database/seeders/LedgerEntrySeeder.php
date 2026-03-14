<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LedgerEntrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Tenant::all()->each(function ($tenant) {
            LedgerEntry::factory()->count(3)->create([
                'tenant_id' => $tenant->id,
                'customer_id' => $tenant->customer_id,
                'store_id' => $tenant->store_id,
            ]);
        });
    }
}
