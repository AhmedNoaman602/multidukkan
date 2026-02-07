<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;

class CustomerController extends Controller
{
    //
    public function index()
    {
        $customers = Customer::all();
        return view('customers.index', compact('customers'));
    }
    public function create()
    {
        return view('customers.create');
    }
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:customers,email',
            'phone' => 'nullable|string|max:20|unique:customers,phone',
            'address' => 'nullable|string|max:500',
            'balance' => 'nullable|numeric|min:0',
            'price_tier' => 'nullable|string|in:standard,wholesale,vip',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        Customer::create($request->all());

        return redirect()->route('customers.index')
            ->with('success', 'Customer created successfully.');
    }
    public function show(Customer $customer)
    {
        return view('customers.show', compact('customer'));
    }
    public function edit(Customer $customer)
    {
        return view('customers.edit', compact('customer'));
    }
    public function update(Request $request, Customer $customer)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:customers,email,' . $customer->id,
            'phone' => 'nullable|string|max:20|unique:customers,phone,' . $customer->id,
            'address' => 'nullable|string|max:500',
            'balance' => 'nullable|numeric|min:0',
            'price_tier' => 'nullable|string|in:standard,wholesale,vip',
            'status' => 'nullable|string|in:active,inactive',
        ]);

        $customer->update($request->all());

        return redirect()->route('customers.index')
            ->with('success', 'Customer updated successfully.');
    }
    public function destroy(Customer $customer)
    {
        $customer->delete();
        return redirect()->route('customers.index')
            ->with('success', 'Customer deleted successfully.');
    }

    
}
