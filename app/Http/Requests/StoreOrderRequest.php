<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\BelongsToTenant;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Warehouse;
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Phase 3 will handle auth here
    }

    public function rules(): array
    {
        $tenantId = $this->input('tenant_id');

        return [
            'tenant_id'          => 'required|exists:tenants,id',
            'store_id'           => ['required', 'exists:stores,id', new BelongsToTenant(Store::class, $tenantId)],
            'customer_id'        => ['required', 'exists:customers,id', new BelongsToTenant(Customer::class, $tenantId)],
            'created_by'         => 'nullable|exists:users,id',
            'notes'              => 'nullable|string',

            'items'              => 'required|array|min:1',
            'items.*.product_id' => ['required', 'exists:products,id', new BelongsToTenant(Product::class, $tenantId)],
            'items.*.warehouse_id' => ['nullable', 'exists:warehouses,id', new BelongsToTenant(Warehouse::class, $tenantId)],
            'items.*.quantity'   => 'required|integer|min:1',
        ];
    }
}