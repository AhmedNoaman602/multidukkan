<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\BelongsToTenant;
use App\Models\Expense;
use App\Models\Store;

class StoreExpenseRequest extends FormRequest
{
    /**
     * Authorization is handled by ExpensePolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = auth()->user()->tenant_id;

        return [
            // Managers are forced to their own store server-side, so any client-supplied
            // store_id is ignored (empty rule set). Only tenant_admins (store_id = null)
            // may pick a store explicitly, or leave it null for a tenant-wide expense.
            'store_id'     => $this->user()->store_id
                                ? []
                                : ['nullable', 'exists:stores,id', new BelongsToTenant(Store::class, $tenantId)],
            'category'     => ['required', Rule::in(Expense::CATEGORIES)],
            // Upper bound guards decimal(10,2) overflow (else a 500); decimal:0,2 blocks
            // silent MySQL rounding of extra decimal places.
            'amount'       => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'description'  => ['nullable', 'string', 'max:255',
                                Rule::requiredIf(fn () => $this->input('category') === 'MISCELLANEOUS')],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
