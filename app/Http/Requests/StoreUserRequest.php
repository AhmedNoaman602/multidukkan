<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Store;
use App\Rules\BelongsToTenant;

class StoreUserRequest extends FormRequest
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
        $tenantId = auth()->user()->tenant_id;

        $allowedRoles = match (auth()->user()->role) {
            'tenant_admin'  => ['store_manager', 'store_staff'],
            'store_manager' => ['store_staff'],
            default         => [],
        };

        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role'     => ['required', 'in:' . implode(',', $allowedRoles)],
            'store_id' => ['required', 'integer', new BelongsToTenant(Store::class, $tenantId)],
        ];
    }
}
