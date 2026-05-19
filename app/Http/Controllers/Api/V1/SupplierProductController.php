<?php

namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
class SupplierProductController extends Controller
{
    public function index(Supplier $supplier) : JsonResponse
    {
        $this->authorizeTenant($supplier);
        
        $products = $supplier->products()->select(
        'products.id',
        'products.name',
        'products.sku',
        'products.price',
        'products.price_a',
        'products.price_b',
        'products.price_c',
        'products.price_d',
        'products.price_e',
        'products.conversion_factor',
        'products.unit',
        'products.secondary_unit'
    )
            ->get();

            return response()->json([
                'data' => $products
            ]);
    }

    public function attach(Supplier $supplier, Product $product) : JsonResponse
    {
        $this->authorizeTenant($supplier);
        $this->authorizeProductTenant($product);

        $supplier->products()->syncWithoutDetaching([$product->id]);

        return response()->json([
            'message' => 'Product attached to supplier'
        ]);
    }
    public function detach(Supplier $supplier, Product $product) : JsonResponse
    {
        $this->authorizeTenant($supplier);
        $this->authorizeProductTenant($product);

        $supplier->products()->detach($product->id);

        return response()->json([
            'message' => 'Product detached from supplier'
        ]);
    }

    private function authorizeTenant(Supplier $supplier): void
    {
        abort_if(
            $supplier->tenant_id !== auth()->user()->tenant_id,
            403,
            'This supplier does not belong to your tenant.'
        );
    }

    private function authorizeProductTenant(Product $product): void
    {
        abort_if(
            $product->tenant_id !== auth()->user()->tenant_id,
            403,
            'This product does not belong to your tenant.'
        );
    }
}
