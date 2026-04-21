<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BelongsToTenant implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    
    public function __construct(
        private string $model,
        private int $tenantId
    ){}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = $this->model::where('id' , $value)
        ->where('tenant_id' , $this->tenantId)
        ->exists();

        if(!$exists){
            $fail('The selected :attribute does not belong to this tenant.');
        }
    }
}
