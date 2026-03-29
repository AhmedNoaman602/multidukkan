<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\StoreProductRequest;
use App\Models\Product;
use App\Http\Resources\ProductResource;
class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $this->authorize('viewAny', Product::class);

        $user = auth()->user();
        $products = Product::where('tenant_id',$user->tenant_id)
        ->get();
        return ProductResource::collection($products);
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
            'unit'      => $request->unit ?? 'pcs',
        ]);

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
    public function update(StoreProductRequest $request, Product $product)
    {
        $this->authorize('update', $product);

        if ($product->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $product->update([
            'name'  => $request->name,
            'sku'   => $request->sku,
            'price' => $request->price,
            'unit'  => $request->unit ?? $product->unit,
        ]);
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
        
        $product->delete();
        
        return response()->json(['message' => 'Product deleted successfully']);
    }
}
