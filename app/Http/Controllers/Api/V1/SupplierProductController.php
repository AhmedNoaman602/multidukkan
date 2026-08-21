<?php

namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SupplierProductController extends Controller
{
    public function attach(Supplier $supplier, Product $product , Request $request) : JsonResponse
    {
        $this->authorizeTenant($supplier);
        $this->authorizeProductTenant($product);
        
        $request->validate([
            'cost_price'   => 'nullable|numeric',
            'is_preferred' => 'nullable|boolean',
            'notes'        => 'nullable|string|max:255',
        ]);

        $supplier->products()->syncWithoutDetaching([
            $product->id => [
                'cost_price'   => $request->cost_price,
                'is_preferred' => $request->is_preferred,
                'notes'        => $request->notes,
            ]
        ]);

        return response()->json([
            'message' => __('messages.supplier_product_attached')
        ]);
    }

    public function bulkAttach(Supplier $supplier, Request $request): JsonResponse
{
    $this->authorizeTenant($supplier);

    $request->validate([
        'products'                  => 'required|array|min:1',
        'products.*.product_id'     => 'required|exists:products,id',
        'products.*.cost_price'     => 'nullable|numeric',
        'products.*.is_preferred'   => 'nullable|boolean',
        'products.*.notes'          => 'nullable|string|max:255',
    ]);
    $productIds = collect($request->products)->pluck('product_id')->unique();
    $products = Product::whereIn('id', $productIds)->where('tenant_id', auth()->user()->tenant_id)->get()->keyBy('id');
    
    $syncData = [];
    foreach ($request->products as $item) {
        $product = $products->get($item['product_id']);

        if (! $product) {
            abort(403, __('messages.supplier_products_tenant_mismatch'));
        }

        $syncData[$item['product_id']] = [
            'cost_price'   => $item['cost_price'] ?? null,
            'is_preferred' => $item['is_preferred'] ?? false,
            'notes'        => $item['notes'] ?? null,
        ];
    }

    $supplier->products()->syncWithoutDetaching($syncData);

    return response()->json(['message' => __('messages.supplier_products_linked')]);
}

    public function update(Supplier $supplier , Product $product , Request $request) : JsonResponse{
        $this->authorizeTenant($supplier);
        $this->authorizeProductTenant($product);

        $request->validate([
            'cost_price'   => 'nullable|numeric',
            'is_preferred' => 'nullable|boolean',
            'notes'        => 'nullable|string|max:255',
        ]);

        $supplier->products()->updateExistingPivot(
            $product->id , [
                'cost_price'   => $request->cost_price,
                'is_preferred' => $request->is_preferred,
                'notes'        => $request->notes,
        ]);

        return response()->json([
            'message' => __('messages.supplier_product_updated')
        ]);
    }


    public function detach(Supplier $supplier, Product $product) : JsonResponse
    {
        $this->authorizeTenant($supplier);
        $this->authorizeProductTenant($product);

        $supplier->products()->detach($product->id);

        return response()->json([
            'message' => __('messages.supplier_product_detached')
        ]);
    }



    private function authorizeTenant(Supplier $supplier): void
    {
        abort_if(
            $supplier->tenant_id !== auth()->user()->tenant_id,
            403,
            __('messages.supplier_tenant_mismatch')
        );
    }

    private function authorizeProductTenant(Product $product): void
    {
        abort_if(
            $product->tenant_id !== auth()->user()->tenant_id,
            403,
            __('messages.product_tenant_mismatch')
        );
    }
}
