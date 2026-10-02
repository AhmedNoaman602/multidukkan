<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function __construct(protected InventoryService $inventory){}

    public function deleteProduct(Product $product): void
    {
        DB::transaction(fn () => $product->delete());
    }

    public function createProduct(array $data , int $tenantId, int $userId) : Product {
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
                'unit'               => $data['unit'] ?? 'pcs',
                'secondary_unit'     => $data['secondary_unit'] ?? null,
                'conversion_factor'  => $data['conversion_factor'] ?? null,
            ]);

            $product->syncSuppliers($data['supplier_ids'] ?? []);

            // Initial stock is per location; there is no product-level opening quantity.
            $this->syncStocks($product, $data['stocks'] ?? [], $tenantId, $userId, __('messages.stock_note_product_created'));

            return $product;
        });
    }

    /**
     * Set each location's stock to the quantity entered, in the unit entered, plus any loose
     * base units ("2 box + 7 pcs" = 2 × 12 + 7). The server converts to base units with
     * factorFor(); setStock() then logs the difference as an adjustment. With no quantity at
     * all, stock is left alone and only the threshold is updated.
     */
    public function syncStocks(Product $product, array $stocks, int $tenantId, int $userId, string $note, ?string $batchId = null): void
    {
        DB::transaction(function () use ($product, $stocks, $tenantId, $userId, $note, $batchId) {
            foreach ($stocks as $stock) {
                if (empty($stock['warehouse_id'])) continue;

                $quantity = isset($stock['quantity']) || isset($stock['loose_quantity'])
                    ? (int) ($stock['quantity'] ?? 0) * $product->factorFor($stock['unit_type'] ?? 'base')
                        + (int) ($stock['loose_quantity'] ?? 0)
                    : null;

                $this->inventory->setStock(
                    $product->id,
                    (int) $stock['warehouse_id'],
                    $tenantId,
                    $quantity,
                    isset($stock['threshold']) ? (int) $stock['threshold'] : null,
                    $userId,
                    $note,
                    $batchId
                );
            }
        });
    }
}
