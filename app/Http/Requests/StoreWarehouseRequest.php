<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Rules\BelongsToTenant;
use App\Models\Store;
use Illuminate\Support\Facades\Auth;

class StoreWarehouseRequest extends FormRequest
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
        $tenantId = Auth::user()->tenant_id;

        return [
            'name' => 'required|string|max:255',
            'store_id' => $this->user()->store_id
                ? []
                : ['required', 'exists:stores,id', new BelongsToTenant(Store::class, $tenantId)],
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
        ];
    }
}
