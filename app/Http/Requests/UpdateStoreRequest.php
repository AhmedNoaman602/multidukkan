<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreRequest extends FormRequest
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
            'name'    => ['sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s-]{7,20}$/'],
        ];
    }
}
