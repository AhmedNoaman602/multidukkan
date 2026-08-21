<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use App\Models\Expense;

class UpdateExpenseRequest extends FormRequest
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
     * store_id and created_by are immutable after creation, so they are not
     * accepted here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category'     => ['sometimes', 'required', Rule::in(Expense::CATEGORIES)],
            'amount'       => ['sometimes', 'required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'description'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'expense_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * Enforce the "MISCELLANEOUS requires a description" rule against the
     * resulting state, not merely the PATCH payload. A PATCH of
     * {category: "MISCELLANEOUS"} on a row whose stored description is null,
     * with no description in the payload, must fail — a payload-only
     * required_if would let it through.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Expense $expense */
            $expense = $this->route('expense');

            $category    = $this->input('category', $expense->category);
            $description = $this->has('description') ? $this->input('description') : $expense->description;

            if ($category === 'MISCELLANEOUS' && blank($description)) {
                $validator->errors()->add('description', __('messages.description_required_for_misc_expense'));
            }
        });
    }
}
