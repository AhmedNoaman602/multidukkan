<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'      => 'required|numeric|min:0.01|max:99999999.99|decimal:0,2',
            'description' => 'nullable|string|max:255',
        ];
    }
}