<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Store;
use App\Http\Resources\StoreResource;

class StoreController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $stores = Store::where('tenant_id', $user->tenant_id)
            ->when($user->store_id, fn($q) => $q->where('id', $user->store_id))
            ->get();

        return StoreResource::collection($stores);
    }

    public function store(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'phone'   => 'nullable|string|max:20',
        ]);

        $store = Store::create([
            'tenant_id' => $user->tenant_id,
            'name'      => $validated['name'],
            'address'   => $validated['address'] ?? null,
            'phone'     => $validated['phone'] ?? null,
        ]);

        return (new StoreResource($store))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Store $store)
    {
        if ($store->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return new StoreResource($store);
    }

    public function update(Request $request, Store $store)
    {
        if ($store->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'address' => 'nullable|string|max:255',
            'phone'   => 'nullable|string|max:20',
        ]);

        $store->update($validated);

        return new StoreResource($store);
    }

    public function destroy(Store $store)
    {
        if ($store->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $store->delete();

        return response()->json(['message' => 'Store deleted successfully']);
    }
}