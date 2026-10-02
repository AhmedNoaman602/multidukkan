<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\BelongsToTenant;
use App\Models\Warehouse;
use App\Models\Supplier;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = auth()->user()->tenant_id;
        $productId = $this->route('product')->id;

        return [
            'name'               => ['required', 'string', 'max:255'],
            'sku'                => ['required', 'string', Rule::unique('products')->where('tenant_id', $tenantId)->ignore($productId)],
            'price'              => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'cost_price'         => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'unit'               => ['nullable', 'string'],
            'price_a'            => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'price_b'            => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'price_c'            => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'price_d'            => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'price_e'            => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'secondary_unit'     => ['nullable', 'string'],
            // Base units in one secondary unit; 1 would make the secondary unit the base unit. Same rule as create.
            'conversion_factor'  => ['nullable', 'integer', 'min:2'],
            'supplier_ids'       => ['sometimes', 'array'],
            'supplier_ids.*'     => ['integer', new BelongsToTenant(Supplier::class, $tenantId)],
            'stocks'             => ['nullable', 'array'],
            'stocks.*.warehouse_id' => [
                'required_with:stocks',
                'integer',
                'distinct',
                new BelongsToTenant(Warehouse::class, $tenantId),
            ],
            'stocks.*.threshold'    => ['nullable', 'integer', 'min:0'],
            'stocks.*.quantity'     => ['nullable', 'integer', 'min:0'],
            'stocks.*.unit_type'    => ['nullable', 'in:base,secondary'],
            // "2 box + 7 pcs": loose base units on top of a secondary-unit quantity. Input only.
            'stocks.*.loose_quantity' => ['nullable', 'integer', 'min:0', 'prohibited_unless:stocks.*.unit_type,secondary'],
        ];
    }
}