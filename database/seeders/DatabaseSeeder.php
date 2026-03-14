<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Tenant;
use App\Models\Store;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\LedgerEntry;
use App\Models\Product;
use Illuminate\Support\Arr;
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
// database/seeders/DatabaseSeeder.php

public function run(): void
{
    // One tenant
    $tenant = Tenant::create(['id' => 1, 'name' => 'Test Business']);

    // One store
    $store = Store::create([
        'id'        => 1,
        'tenant_id' => 1,
        'name'      => 'Main Store',
        'phone'     => '0000000000',
        'address'   => '123 Main St',
    ]);

    // One customer
    $customer = Customer::create([
        'id'        => 1,
        'tenant_id' => 1,
        'name'      => 'Test Customer',
        'phone'     => '0000000000',
    ]);

    // One product
    $product = Product::create([
        'id'        => 1,
        'tenant_id' => 1,
        'name'      => 'Test Product',
        'sku'       => 'TEST-001',
        'price'     => 100.00,
        'unit'      => 'pcs',
    ]);
}
}
