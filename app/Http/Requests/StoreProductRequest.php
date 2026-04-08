<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class StoreProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenantId = Auth::user()->tenant_id;
        return [
            'name'      => 'required|string|max:255',
            'sku'       => [
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->where('tenant_id', $tenantId),
            ],
            'price'     => 'required|numeric|min:0',
            'price_a'     => 'nullable|numeric|min:0',
            'price_b'     => 'nullable|numeric|min:0',
            'price_c'     => 'nullable|numeric|min:0',
            'price_d'     => 'nullable|numeric|min:0',
            'price_e'     => 'nullable|numeric|min:0',
            'unit'      => 'nullable|string|max:20',
            'secondary_unit'    => 'nullable|string|max:50',
            'conversion_factor' => 'nullable|integer|min:2',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'quantity'     => 'nullable|integer|min:0',
            'threshold'    => 'nullable|integer|min:0',
        ];
    }
}
