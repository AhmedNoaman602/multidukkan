<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'business_name' => ['required', 'string', 'max:255'],
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', 'unique:users,email'],
            'password'      => ['required', 'string', 'min:8', 'confirmed'],
            'invite_code'   => ['bail', 'required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                $expected = (string) config('multidukkan.invite_code');

                if ($expected === '' || ! hash_equals(strtoupper($expected), strtoupper(trim($value)))) {
                    $fail(__('messages.invalid_invite_code'));
                }
            }],
        ];
    }
}
