<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\Inventory;
use App\Models\Warehouse;

class ProductService
{
    /**
     * Create a new class instance.
     */
    public function __construct(){}

    public function createProduct(array $data , int $tenantId, int $userId) : Product {
// User creates product with opening_qty = 25
//         ↓
// Did user pick a warehouse? → use it
// Did they not? → grab tenant's default warehouse
//         ↓
// Does an inventory row exist for product+warehouse?
//     YES → leave it, just add 25 on top (increment)
//     NO  → create it at 0, then add 25 (firstOrCreate + increment)
//         ↓
// Warehouse table now holds the truth: qty = 25
// opening_quantity is never touched again
        return DB::transaction(function() use($data, $tenantId, $userId){
            $product = Product::create([
                'tenant_id'          => $tenantId,
                'name'               => $data['name'],
                'sku'                => $data['sku'],
                'price'              => $data['price'],
                'price_a'            => $data['price_a'] ?? null,
                'price_b'            => $data['price_b'] ?? null,
                'price_c'            => $data['price_c'] ?? null,
                'price_d'            => $data['price_d'] ?? null,
                'price_e'            => $data['price_e'] ?? null,
                'cost_price'         => $data['cost_price'] ?? null,
                'opening_quantity'   => $data['opening_quantity'] ?? 0,
                'unit'               => $data['unit'] ?? 'pcs',
                'secondary_unit'     => $data['secondary_unit'] ?? null,
                'conversion_factor'  => $data['conversion_factor'] ?? null,
                'supplier_id'        => $data['supplier_id'] ?? null,
            ]);

            foreach ($data['stocks'] ?? [] as $stock) {
                if (empty($stock['warehouse_id'])) continue;
                Inventory::create([
                    'tenant_id'    => $tenantId,
                    'warehouse_id' => $stock['warehouse_id'],
                    'product_id'   => $product->id,
                    'quantity'     => $stock['quantity'] ?? 0,
                    'threshold'    => $stock['threshold'] ?? 10,
                ]);
            }
             // Opening stock — one time only, backend owned
            if (!empty($data['opening_quantity']) && $data['opening_quantity'] > 0) {
                $warehouseId = collect($data['stocks'] ?? [])
                    ->pluck('warehouse_id')
                    ->filter()
                    ->first()
                    ?? $this->getDefaultWarehouse($tenantId);
                if (!$warehouseId) {
                    throw new HttpResponseException(
                        response()->json(['message' => 'لا يوجد مخزن متاح. يرجى إنشاء مخزن أولاً.'], 422)
                    );
                }

                $inventory = Inventory::firstOrCreate(
                    [
                        'product_id'   => $product->id,
                        'warehouse_id' => $warehouseId,
                    ],
                    [
                        'tenant_id' => $tenantId,
                        'quantity'  => 0,
                        'threshold' => 10,
                    ]
                );

                $inventory->increment('quantity', (int) $data['opening_quantity']);
            }
            return $product;
        });
        
    }

    private function getDefaultWarehouse(int $tenantId): int
    {
        return Warehouse::where('tenant_id', $tenantId)
            ->orderBy('id', 'asc')
            ->value('id');
    }
}
