<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
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
            'code'       => ['nullable', 'string', 'max:255'],
            'name'       => ['required', 'string', 'max:255'],
            'phone'      => ['required', 'string', 'max:20'],
            'address'    => ['nullable', 'string', 'max:255'],
            'price_tier' => ['nullable', 'in:default,a,b,c,d,e'],
            'area'       => ['nullable', 'string', 'max:255'],
        ];
    }
}
