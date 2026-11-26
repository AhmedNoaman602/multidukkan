<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Whether a settlement is required (something was paid) and which ones are allowed
     * (walk-in: cash only) is decided by OrderService under lock.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'settlement' => ['nullable', 'string', 'in:cash,credit'],
        ];
    }
}
