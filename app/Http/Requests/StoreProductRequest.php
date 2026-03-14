<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        return [
            'tenant_id' => 'required|exists:tenants,id',
            'name'      => 'required|string|max:255',
            'sku'       => [
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->where('tenant_id', $this->input('tenant_id')),
            ],
            'price'     => 'required|numeric|min:0',
            'unit'      => 'nullable|string|max:20',
        ];
    }
}
