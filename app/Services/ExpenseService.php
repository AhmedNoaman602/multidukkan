<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public function getTotalForPeriod(int $tenantId, string $from, string $to): float
    {
        return (float) Expense::where('tenant_id', $tenantId)
            ->where('expense_date', '>=', $from)
            ->where('expense_date', '<=', $to)
            ->sum('amount');
    }

    public function getCategoryBreakdownForPeriod(int $tenantId, string $from, string $to): Collection
    {
        return Expense::where('tenant_id', $tenantId)
            ->where('expense_date', '>=', $from)
            ->where('expense_date', '<=', $to)
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['category' => $row->category, 'total' => round((float) $row->total, 2)])
            ->values();
    }

    public function createExpense(array $data , User $user): Expense
    {
        return DB::transaction(function () use ($data, $user) {
            $storeId = $user->store_id ?? ($data['store_id'] ?? null);

            return Expense::create([
                'tenant_id' => $user->tenant_id,
                'store_id' => $storeId,
                'created_by' => $user->id,
                'created_by_name' => $user->name,
                'category' => $data['category'],
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
                'expense_date' => $data['expense_date'],
            ]);
        });
    }

 public function updateExpense(Expense $expense, array $data): Expense {
return DB::transaction(function () use ($expense, $data) {
            $attributes = [
                'category' => $data['category'] ?? $expense->category,
                'amount' => $data['amount'] ?? $expense->amount,
                'expense_date' => $data['expense_date'] ?? $expense->expense_date,
            ];

            if(array_key_exists('description', $data)) {
                $attributes['description'] = $data['description'];
            }
            $expense->update($attributes);
            return $expense;
        });
  }

  public function deleteExpense(Expense $expense): void
    {
         DB::transaction(function () use ($expense) {
            return $expense->delete();
        });
    }
}