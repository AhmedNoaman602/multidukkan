<?php

namespace App\Rules;

use App\Models\Warehouse;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class WarehouseInStore implements ValidationRule
{
    public function __construct(
        private ?int $storeId
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = $this->storeId
            && Warehouse::where('id', $value)
                ->where('store_id', $this->storeId)
                ->exists();

        if (!$exists) {
            $fail(__('messages.warehouse_not_in_store'));
        }
    }
}
