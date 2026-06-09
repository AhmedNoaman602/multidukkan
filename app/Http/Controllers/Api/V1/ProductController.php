<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Models\Inventory;
use App\Http\Resources\ProductResource;
class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
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

    return ProductResource::collection($query->paginate(20));
}

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProductRequest $request)
    {
        $this->authorize('create', Product::class);

        $user = auth()->user();
        $product = Product::create([
            'tenant_id' => $user->tenant_id,
            'name'      => $request->name,
            'sku'       => $request->sku,
            'price'     => $request->price,
            'price_a'   => $request->price_a,
            'price_b'   => $request->price_b,
            'price_c'   => $request->price_c,
            'price_d'   => $request->price_d,
            'price_e'   => $request->price_e,
            'cost_price' => $request->cost_price,
            'unit'      => $request->unit ?? 'pcs',
            'secondary_unit'    => $request->secondary_unit,
            'conversion_factor' => $request->conversion_factor,
            'supplier_id' => $request->supplier_id,
        ]);
        foreach ($request->stocks ?? [] as $stock) {
    if (empty($stock['warehouse_id'])) continue;
    Inventory::create([
        'tenant_id'    => $user->tenant_id,
        'warehouse_id' => $stock['warehouse_id'],
        'product_id'   => $product->id,
        'quantity'     => $stock['quantity'] ?? 0,
        'threshold'    => $stock['threshold'] ?? 10,
    ]);
}
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
            return response()->json(['message' => 'Unauthorized'], 403);
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
            return response()->json(['message' => 'Unauthorized'], 403);
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

        foreach ($request->stocks ?? [] as $stock) {
        if (empty($stock['warehouse_id'])) continue;

        $existing = Inventory::where('product_id', $product->id)
            ->where('warehouse_id', $stock['warehouse_id'])
            ->first();

        if ($existing) {
            $existing->update([
                'quantity' => $stock['quantity'] ?? $existing->quantity,
                'threshold' => $stock['threshold'] ?? $existing->threshold,
            ]);
        } else {
            Inventory::create([
                'tenant_id'    => $user->tenant_id,
                'warehouse_id' => $stock['warehouse_id'],
                'product_id'   => $product->id,
                'quantity'     => $stock['quantity'] ?? 0,
                'threshold'    => $stock['threshold'] ?? 10,
            ]);
        }
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
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    // Block deletion if product has stock
    $hasStock = $product->inventories()
        ->where('quantity', '>', 0)
        ->exists();

    if ($hasStock) {
        return response()->json([
            'message' => 'Cannot delete product with existing inventory. Reduce stock to zero first.'
        ], 422);
    }

    $product->delete();

    return response()->json(['message' => 'Product deleted successfully']);
}
}
