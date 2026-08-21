<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Http\Resources\ProductResource;
use App\Services\ProductService;
use App\Services\InventoryService;
use App\Http\Resources\ProductSupplierResource;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function __construct(protected ProductService $productService, protected InventoryService $inventoryService) {}

    public function index(Request $request)
{
    $this->authorize('viewAny', Product::class);

    $user = auth()->user();

    $query = Product::where('tenant_id', $user->tenant_id)
        ->when($request->search, function ($q) use ($request) {
            $q->where('name', 'like', "%$request->search%")
              ->orWhere('sku', 'like', "%$request->search%");
        })
        ->orderBy('name', 'asc');

    if ($request->per_page === 'all') {
        return ProductResource::collection($query->get());
    }

    return ProductResource::collection($query->paginate(15));
}

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProductRequest $request)
    {
        $this->authorize('create', Product::class);

        $user = auth()->user();
     
 $product = $this->productService->createProduct(
        $request->validated(),
        $user->tenant_id,
        $user->id
    );

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {   
        $this->authorize('view', $product);

        if ($product->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }
        
        return new ProductResource($product);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProductRequest $request, Product $product)
    {
        $this->authorize('update', $product);

        if ($product->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }
        
        $product->update([
            'name'    => $request->name,
            'sku'     => $request->sku,
            'price'   => $request->price,
            'price_a' => $request->price_a,
            'price_b' => $request->price_b,
            'price_c' => $request->price_c,
            'price_d' => $request->price_d,
            'price_e' => $request->price_e,
            'cost_price' => $request->cost_price,
            'unit'    => $request->unit ?? $product->unit,
            'supplier_id' => $request->supplier_id,
            'secondary_unit' => $request->secondary_unit,
            'conversion_factor' => $request->conversion_factor,
        ]);

        $user = auth()->user();

        $batchId = (string) Str::uuid();

        try {
            foreach ($request->stocks ?? [] as $stock) {
                if (empty($stock['warehouse_id'])) continue;

                $this->inventoryService->setStock(
                    $product->id,
                    $stock['warehouse_id'],
                    $user->tenant_id,
                    $stock['quantity'] ?? null,
                    $stock['threshold'] ?? null,
                    $user->id,
                    'Product stock edit',
                    $batchId
                );
            }
        } catch (ValidationException $e) {
            return response()->json([
                'message' => __('messages.validation_error'),
                'errors' => $e->errors(),
            ], 422);
        }

        return new ProductResource($product);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
{
    $this->authorize('delete', $product);

    if ($product->tenant_id !== auth()->user()->tenant_id) {
        return response()->json(['message' => __('messages.unauthorized')], 403);
    }

    // Block deletion if product has stock
    $hasStock = $product->inventories()
        ->where('quantity', '>', 0)
        ->exists();

    if ($hasStock) {
        return response()->json([
            'message' => __('messages.product_has_inventory')
        ], 422);
    }

    $product->delete();

    return response()->json(['message' => __('messages.product_deleted')]);
}

public function suppliers(Product $product)
{
    if ($product->tenant_id !== auth()->user()->tenant_id) {
        return response()->json(['message' => __('messages.unauthorized')], 403);
    }

    $suppliers = $product->suppliers()->get();
    return ProductSupplierResource::collection($suppliers);
}

}
