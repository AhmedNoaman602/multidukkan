<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Store;
use App\Http\Resources\StoreResource;
use App\Http\Requests\StoreStoreRequest;
use App\Http\Requests\UpdateStoreRequest;
use App\Services\StoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreController extends Controller
{
    public function __construct(protected StoreService $storeService) {}

    public function index()
    {
        $this->authorize('viewAny', Store::class);
        
        $user = auth()->user();

        $stores = Store::where('tenant_id', $user->tenant_id)
            ->when($user->store_id, fn($q) => $q->where('id', $user->store_id))
            ->with('shelf')
            ->get();

        return StoreResource::collection($stores);
    }

    public function store(StoreStoreRequest $request)
    {
        $this->authorize('create', Store::class);

        $user = auth()->user();

        $store = $this->storeService->createStore($request->validated(), $user->tenant_id);

        return (new StoreResource($store))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Store $store)
    {
        $this->authorize('view', $store);
        
        if ($store->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        return new StoreResource($store->load('shelf'));
    }

    public function update(UpdateStoreRequest $request, Store $store)
    {
        $this->authorize('update', $store);

        if ($store->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }

        $validated = $request->validated();

        $store->update($validated);

        return new StoreResource($store->load('shelf'));
    }

    public function destroy(Store $store)
{
    $this->authorize('delete', $store);

    if ($store->tenant_id !== auth()->user()->tenant_id) {
        return response()->json(['message' => __('messages.unauthorized')], 403);
    }

    try {
        DB::transaction(fn () => $store->delete());
        return response()->json(['message' => __('messages.store_deleted')]);
    } catch (ValidationException $e) {
        return response()->json(['message' => $e->errors()['store'][0]], 422);
    }
}
}