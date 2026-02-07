<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Inventory;
use App\Models\Product;
class InventoryController extends Controller
{
    public function index()
    {
        $inventories = Inventory::all();
        $generalStock = \App\Models\Product::doesntHave('inventories')->get();

        return view('inventory.index', compact('inventories' , 'generalStock'));
    }
    public function show(Inventory $inventory)
    {
        $inventory->load('products');
        return view('inventory.show', compact('inventory'));
    }
    public function create()
    {
        $products = Product::doesntHave('inventories')->get();
        return view('inventory.create', compact('products'));
    }
    public function edit(Inventory $inventory)
    {
        $products = Product::whereDoesntHave('inventories')
            ->orWhereHas('inventories', function($query) use ($inventory) {
                $query->where('inventories.id', $inventory->id);
            })
            ->get();
        
        $assignedProductIds = $inventory->products->pluck('id')->toArray();
        return view('inventory.edit', compact('inventory', 'products', 'assignedProductIds'));
    }
    public function store(Request $request) {
    $inventory = Inventory::create($request->only('name', 'location'));
    $inventory->products()->sync($request->product_ids);
    return redirect()->route('inventory.index');
}

public function update(Request $request, Inventory $inventory) {
    $inventory->update($request->only('name', 'location'));
    $inventory->products()->sync($request->product_ids);
    return redirect()->route('inventory.show', $inventory);
}
    
    public function destroy(Inventory $inventory)
    {
        $inventory->delete();
        return redirect()->route('inventory.index');
    }
}
