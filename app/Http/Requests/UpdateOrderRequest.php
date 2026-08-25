<?php

namespace App\Http\Requests;

use App\Support\LocalDateRange;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
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
            'notes'      => ['sometimes', 'nullable', 'string', 'max:500'],
            // See StoreOrderRequest — "today" is the shop's calendar day, not UTC's
            // and not the viewer's.
            'order_date' => [
                'sometimes',
                'date',
                'before_or_equal:' . LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString(),
            ],
            'discount'   => ['sometimes', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }
}
