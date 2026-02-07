<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Inventory;
class ProductController extends Controller
{
    public function index()
    {
        $products = Product::all();

        
        return view('products.index', compact('products'));
    }

    public function create()
    {
        $inventories = \App\Models\Inventory::all()->map(function($inventory) {
            return [
                'id' => $inventory->id,
                'name' => $inventory->name,
                'subtext' => $inventory->location
            ];
        });

        return view('products.create', compact('inventories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|unique:products,sku',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'low_stock_alert' => 'nullable|integer|min:0',
            'unit' => 'required|string',
            'inventory_id' => 'nullable|exists:inventories,id',
        ]);

        $product = Product::create($request->all());
        
        if ($request->filled('inventory_id')) {
            $product->inventories()->attach($request->inventory_id);
        }

        return redirect()->route('products.index')->with('success', 'Product created successfully.');
    }

    function edit(Product $product)
    {
        $inventories = \App\Models\Inventory::all()->map(function($inventory) {
            return [
                'id' => $inventory->id,
                'name' => $inventory->name,
                'subtext' => $inventory->location
            ];
        });
        return view('products.edit', compact('product', 'inventories'));
    }
    function update(Request $request , Product $product)
    {
        $request->validate([
            'name' => 'required',
            'sku' => 'required',
            'price' => 'required',
            'stock_quantity' => 'required',
            'unit' => 'required',
            'inventory_id' => 'nullable|exists:inventories,id',
        ]);
        
        $product->update($request->all());

        if ($request->has('inventory_id')) {
            $product->inventories()->sync($request->inventory_id ? [$request->inventory_id] : []);
        }

        return redirect()->route('products.index')->with('success', 'Product updated successfully.');
    }
    function destroy(Product $product)
    {
        $product->delete();
        return redirect()->route('products.index')->with('success', 'Product deleted successfully.');
    }
}
