<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Http\Resources\CustomerResource;
class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();
        $customers = Customer::where('tenant_id',$user->tenant_id)
        ->get();
        return CustomerResource::collection($customers);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name' => 'required',
            'phone' => 'required',
            'address' => 'nullable',
        ]);

        $customer = Customer::create([
            'tenant_id'           => $user->tenant_id,
            'created_by_store_id' => $user->store_id,
            'name'                => $validated['name'],
            'phone'               => $validated['phone'],
            'address'             => $validated['address'] ?? null,
        ]);

        return (new CustomerResource($customer))
                ->response()
                ->setStatusCode(201);    
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer)
    {
        if ($customer->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return new CustomerResource($customer);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Customer $customer)
    {
        if ($customer->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'phone'   => 'sometimes|string|max:20',
            'address' => 'nullable|string|max:255',
        ]);        
        
        $customer->update($validated);
        return new CustomerResource($customer);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Customer $customer)
    {
        if ($customer->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $customer->delete();
        return response()->json(['message' => 'Customer deleted successfully']);
    }
}
