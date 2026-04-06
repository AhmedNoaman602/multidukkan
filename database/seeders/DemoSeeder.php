<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use App\Models\Store;
use App\Models\User;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Inventory;
use App\Models\Customer;

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

        User::create([
            'tenant_id' => $tenant->id,
            'store_id'  => $store->id,
            'name'      => 'Manager',
            'email'     => 'manager@multidukkan.com',
            'password'  => bcrypt('password123'),
            'role'      => 'store_manager',
        ]);
$warehouses = [
    ['name' => 'عام مخازن',  'address' => 'المخزن الرئيسي'],
    ['name' => 'الشارع',     'address' => 'الشارع'],
    ['name' => 'الخشب',      'address' => 'مخزن الخشب'],
    ['name' => 'الورشة',     'address' => 'الورشة'],
];

$products = [
    ['name' => 'شاكوش كبير',        'sku' => 'HAM-001', 'price' => 150, 'price_a' => 130, 'price_b' => 120, 'price_c' => 110, 'price_d' => 100, 'price_e' => 90,  'unit' => 'حبة'],
    ['name' => 'شاكوش صغير',        'sku' => 'HAM-002', 'price' => 85,  'price_a' => 75,  'price_b' => 70,  'price_c' => 65,  'price_d' => 60,  'price_e' => 55,  'unit' => 'حبة'],
    ['name' => 'مفك براغي',          'sku' => 'SCR-001', 'price' => 65,  'price_a' => 58,  'price_b' => 54,  'price_c' => 50,  'price_d' => 46,  'price_e' => 42,  'unit' => 'حبة'],
    ['name' => 'كماشة',              'sku' => 'PLR-001', 'price' => 120, 'price_a' => 105, 'price_b' => 98,  'price_c' => 90,  'price_d' => 82,  'price_e' => 75,  'unit' => 'حبة'],
    ['name' => 'متر قياس 5م',        'sku' => 'TAP-001', 'price' => 45,  'price_a' => 40,  'price_b' => 37,  'price_c' => 34,  'price_d' => 31,  'price_e' => 28,  'unit' => 'حبة'],
    ['name' => 'مسامير كيس 1 كيلو', 'sku' => 'NAI-001', 'price' => 30,  'price_a' => 27,  'price_b' => 25,  'price_c' => 23,  'price_d' => 21,  'price_e' => 19,  'unit' => 'كيس'],
];

$createdWarehouses = [];
foreach ($warehouses as $w) {
    $createdWarehouses[] = Warehouse::create([
        'tenant_id' => $tenant->id,
        'store_id'  => $store->id,
        'name'      => $w['name'],
        'address'   => $w['address'],
    ]);
}

        $createdProducts = [];
foreach ($products as $p) {
    $createdProducts[] = Product::create([
    'tenant_id' => $tenant->id,
    'name'      => $p['name'],
    'sku'       => $p['sku'],
    'price'     => $p['price'],
    'price_a'   => $p['price_a'],
    'price_b'   => $p['price_b'],
    'price_c'   => $p['price_c'],
    'price_d'   => $p['price_d'],
    'price_e'   => $p['price_e'],
    'unit'      => $p['unit'],
]);
}

foreach ($createdWarehouses as $warehouse) {
    foreach ($createdProducts as $product) {
        Inventory::create([
            'tenant_id'    => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_id'   => $product->id,
            'quantity'     => 100,
            'threshold'    => 10,
        ]);
    }
}

        Customer::create([
            'tenant_id' => $tenant->id,
            'name'      => 'محمد المقاول',
            'phone'     => '01111111111',
            'address'   => 'مدينة نصر، القاهرة',
        ]);
    }
}