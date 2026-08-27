<?php

namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Requests\AttachSupplierProductRequest;
use App\Http\Requests\BulkAttachSupplierProductRequest;
use App\Http\Requests\UpdateSupplierProductRequest;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;

class SupplierProductController extends Controller
{
    private const PIVOT_FIELDS = ['cost_price', 'is_preferred', 'notes'];

    public function attach(Supplier $supplier, Product $product , AttachSupplierProductRequest $request) : JsonResponse
    {
        $this->authorizeTenant($supplier);
        $this->authorizeProductTenant($product);
        $this->authorize('update', $product);

        $supplier->syncProducts([
            $product->id => $this->pivotPayload($request->validated()),
        ]);

        return response()->json([
            'message' => __('messages.supplier_product_attached')
        ]);
    }

    public function bulkAttach(Supplier $supplier, BulkAttachSupplierProductRequest $request): JsonResponse
{
    $this->authorizeTenant($supplier);

    $validated = $request->validated();
    $productIds = collect($validated['products'])->pluck('product_id')->unique();
    $products = Product::whereIn('id', $productIds)->where('tenant_id', auth()->user()->tenant_id)->get()->keyBy('id');

    $syncData = [];
    foreach ($validated['products'] as $item) {
        $product = $products->get($item['product_id']);

        if (! $product) {
            abort(403, __('messages.supplier_products_tenant_mismatch'));
        }

        $this->authorize('update', $product);

        $syncData[$item['product_id']] = $this->pivotPayload($item);
    }

    $supplier->syncProducts($syncData);

    return response()->json(['message' => __('messages.supplier_products_linked')]);
}

    public function update(Supplier $supplier , Product $product , UpdateSupplierProductRequest $request) : JsonResponse{
        $this->authorizeTenant($supplier);
        $this->authorizeProductTenant($product);
        $this->authorize('update', $product);

        $payload = $this->pivotPayload($request->validated());

        if ($payload !== []) {
            $supplier->products()->updateExistingPivot($product->id, $payload);
        }

        return response()->json([
            'message' => __('messages.supplier_product_updated')
        ]);
    }


    public function detach(Supplier $supplier, Product $product) : JsonResponse
    {
        $this->authorizeTenant($supplier);
        $this->authorizeProductTenant($product);
        $this->authorize('update', $product);

        $supplier->products()->detach($product->id);

        return response()->json([
            'message' => __('messages.supplier_product_detached')
        ]);
    }


    private function pivotPayload(array $data): array
    {
        return array_intersect_key($data, array_flip(self::PIVOT_FIELDS));
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
