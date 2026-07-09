<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'       => ['sometimes', 'string', 'max:255'],
            'phone'      => ['sometimes', 'string', 'max:20'],
            'address'    => ['nullable', 'string', 'max:255'],
            'price_tier' => ['sometimes', 'nullable', 'in:default,a,b,c,d,e'],
            'area'       => ['nullable', 'string', 'max:255'],
            'code'       => ['nullable', 'string', 'max:255'],
        ];
    }
}
