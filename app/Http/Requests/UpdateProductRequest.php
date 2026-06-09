<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'price'              => ['required', 'numeric', 'min:0'],
            'cost_price'         => ['nullable', 'numeric', 'min:0'],
            'unit'               => ['nullable', 'string'],
            'price_a'            => ['nullable', 'numeric', 'min:0'],
            'price_b'            => ['nullable', 'numeric', 'min:0'],
            'price_c'            => ['nullable', 'numeric', 'min:0'],
            'price_d'            => ['nullable', 'numeric', 'min:0'],
            'price_e'            => ['nullable', 'numeric', 'min:0'],
            'secondary_unit'     => ['nullable', 'string'],
            'conversion_factor'  => ['nullable', 'integer', 'min:1'],
            'stocks'             => ['nullable', 'array'],
            'stocks.*.warehouse_id' => ['required_with:stocks', 'integer', 'exists:warehouses,id'],
            'stocks.*.threshold'    => ['nullable', 'integer', 'min:0'],
            'stocks.*.quantity'     => ['nullable', 'integer', 'min:0'],
        ];
    }
}