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
use App\Models\Supplier;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PurchaseOrderService;
use App\Services\SupplierPaymentService;
use Illuminate\Support\Facades\Auth;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::create([
            'name' => 'Noaman Tools',
        ]);

        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Main Store',
            'address'   => 'Alexandria, Egypt',
            'phone'     => '01000000000',
        ]);

        Customer::create([
            'tenant_id'  => $tenant->id,
            'name'       => 'زبون نقدي',
            'phone'      => '00000000000',
            'is_walk_in' => true,
        ]);

        $admin = User::create([
            'tenant_id' => $tenant->id,
            'store_id'  => null,
            'name'      => 'Noaman',
            'email'     => 'noaman@multidukkan.com',
            'password'  => bcrypt('password123'),
            'role'      => 'tenant_admin',
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'store_id'  => $store->id,
            'name'      => 'Ahmed',
            'email'     => 'ahmed@multidukkan.com',
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
    ['name' => 'شاكوش كبير',        'sku' => 'HAM-001', 'price' => 150, 'price_a' => 130, 'price_b' => 120, 'price_c' => 110, 'price_d' => 100, 'price_e' => 90,  'unit' => 'حته'],
    ['name' => 'شاكوش صغير',        'sku' => 'HAM-002', 'price' => 85,  'price_a' => 75,  'price_b' => 70,  'price_c' => 65,  'price_d' => 60,  'price_e' => 55,  'unit' => 'حته'],
    ['name' => 'مفك براغي',          'sku' => 'SCR-001', 'price' => 65,  'price_a' => 58,  'price_b' => 54,  'price_c' => 50,  'price_d' => 46,  'price_e' => 42,  'unit' => 'حته'],
    ['name' => 'كماشة',              'sku' => 'PLR-001', 'price' => 120, 'price_a' => 105, 'price_b' => 98,  'price_c' => 90,  'price_d' => 82,  'price_e' => 75,  'unit' => 'حته'],
    ['name' => 'متر قياس 5م',        'sku' => 'TAP-001', 'price' => 45,  'price_a' => 40,  'price_b' => 37,  'price_c' => 34,  'price_d' => 31,  'price_e' => 28,  'unit' => 'حته'],
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

        $contractor = Customer::create([
            'tenant_id' => $tenant->id,
            'name'      => 'محمد المقاول',
            'phone'     => '01111111111',
            'address'   => 'مدينة نصر، القاهرة',
        ]);

        // ─── Demo orders, purchase order, and payments ───
        // Runs through the real services (not raw Eloquent::create) so this exercises the
        // actual invoice/ledger/stock logic — same code paths covered by OrderMoneyTest /
        // PurchaseOrderMoneyTest — giving real data to click through in the app.
        Auth::setUser($admin);

        $orderService = app(OrderService::class);
        $paymentService = app(PaymentService::class);
        $purchaseOrderService = app(PurchaseOrderService::class);
        $supplierPaymentService = app(SupplierPaymentService::class);

        $hammer = $createdProducts[0];   // شاكوش كبير — price 150
        $screwdriver = $createdProducts[2]; // مفك براغي — price 65
        $mainWarehouse = $createdWarehouses[0];

        // Order 1 — paid in full at creation (pay_immediately)
        $orderService->createOrder([
            'customer_id' => $contractor->id,
            'store_id'    => $store->id,
            'order_date'  => now()->subDays(3)->toDateString(),
            'notes'       => 'طلب عادي',
            'items'       => [
                ['product_id' => $hammer->id, 'quantity' => 2, 'warehouse_id' => $mainWarehouse->id],
                ['product_id' => $screwdriver->id, 'quantity' => 3, 'warehouse_id' => $mainWarehouse->id],
            ],
            'pay_immediately' => true,
            'payment_method'  => 'cash',
        ]);

        // Order 2 — manual_total override: subtotal would be 300 (2 x 150), owner charges 250 as goodwill
        $manualTotalOrder = $orderService->createOrder([
            'customer_id' => $contractor->id,
            'store_id'    => $store->id,
            'order_date'  => now()->subDay()->toDateString(),
            'notes'       => 'خصم خاص للعميل',
            'items'       => [
                ['product_id' => $hammer->id, 'quantity' => 2, 'warehouse_id' => $mainWarehouse->id],
            ],
            'manual_total' => 250,
        ]);

        // Order 3 — left partially paid, to demo the manager-only edit lock in the UI
        $partialOrder = $orderService->createOrder([
            'customer_id' => $contractor->id,
            'store_id'    => $store->id,
            'order_date'  => now()->toDateString(),
            'notes'       => 'دفعة جزئية',
            'items'       => [
                ['product_id' => $screwdriver->id, 'quantity' => 4, 'warehouse_id' => $mainWarehouse->id],
            ],
        ]);
        $paymentService->processDirectPayment([
            'order_id'    => $partialOrder->id,
            'customer_id' => $contractor->id,
            'amount'      => 100,
            'method'      => 'cash',
        ], $admin);

        // Purchase order — restocking from a supplier
        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'name'      => 'مورد الأدوات المتحدة',
            'phone'     => '01222222222',
            'address'   => 'العبور، القاهرة',
        ]);

        $purchaseOrder = $purchaseOrderService->createPurchaseOrder([
            'supplier_id' => $supplier->id,
            'notes'       => 'توريد شهري',
            'items'       => [
                ['product_id' => $hammer->id, 'quantity' => 20, 'warehouse_id' => $mainWarehouse->id, 'unit_price' => 95],
                ['product_id' => $screwdriver->id, 'quantity' => 30, 'warehouse_id' => $mainWarehouse->id, 'unit_price' => 40],
            ],
        ]);

        // Partial payment to the supplier
        $supplierPaymentService->processSupplierPayment([
            'supplier_id'        => $supplier->id,
            'purchase_order_id'  => $purchaseOrder->id,
            'amount'             => 1000,
            'method'             => 'cash',
        ], $admin);

    }
}