<?php

namespace App\Http\Requests;

use App\Models\Store;
use App\Rules\BelongsToTenant;
use Illuminate\Foundation\Http\FormRequest;

class StockAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->user();

        return [
            // Store users always get their own store; only an admin chooses one.
            'store_id'      => $user->store_id
                ? ['nullable']
                : ['required', 'integer', new BelongsToTenant(Store::class, $user->tenant_id)],
            'product_ids'   => 'required|array|min:1|max:50',
            'product_ids.*' => 'required|integer|distinct',
        ];
    }
}
