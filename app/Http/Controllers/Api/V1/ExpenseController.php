<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Expense;
use App\Services\ExpenseService;
use App\Http\Resources\ExpenseResource;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;

class ExpenseController extends Controller
{
    public function __construct(protected ExpenseService $expenseService) {}  

    public function index(Request $request)
    {
        $this->authorize('viewAny', Expense::class);

        $user = auth()->user();

        $query = Expense::where('tenant_id', $user->tenant_id)
            ->when($user->store_id, fn ($q) => $q->where('store_id', $user->store_id))
            ->when($request->store_id && ! $user->store_id, fn ($q) =>
                $q->where('store_id', $request->store_id))

            ->when($request->category, fn ($q) =>
                $q->where('category', $request->category))
            // Plain where on the date column (not whereDate) keeps the
            // (tenant_id, expense_date) index usable.
            ->when($request->date_from, fn ($q) =>
                $q->where('expense_date', '>=', $request->date_from))

            ->when($request->date_to, fn ($q) =>
                $q->where('expense_date', '<=', $request->date_to))
            ->with('store:id,name', 'creator:id,name');

        // Sort — whitelist only; never trust raw input in orderBy.
        $sortBy  = in_array($request->sort_by, ['expense_date', 'amount']) ? $request->sort_by : 'expense_date';
        $sortDir = in_array($request->sort_dir, ['asc', 'desc']) ? $request->sort_dir : 'desc';

        // Stats computed on the filtered set, before pagination.
        $totalAmount = (clone $query)->sum('amount');

        $expenses = $query
            ->orderBy($sortBy, $sortDir)
            ->orderBy('id', 'desc') // stable tiebreaker when many rows share a date
            ->paginate(25);

        return response()->json([
            'data' => ExpenseResource::collection($expenses)->resolve(),
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'last_page'    => $expenses->lastPage(),
                'total'        => $expenses->total(),
            ],
            'stats' => [
                'total_amount' => (float) $totalAmount,
            ],
        ]);
    }

    public function store(StoreExpenseRequest $request)
    {
        $this->authorize('create', Expense::class);

        $expense = $this->expenseService->createExpense($request->validated(), auth()->user());

        return (new ExpenseResource($expense))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        $this->authorize('update', $expense);

        // Belt-and-suspenders tenant re-check, matching every other controller.
        if ($expense->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $expense = $this->expenseService->updateExpense($expense, $request->validated());

        return new ExpenseResource($expense);
    }

    public function destroy(Expense $expense)
    {
        $this->authorize('delete', $expense);

        if ($expense->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->expenseService->deleteExpense($expense);

        return response()->json(['message' => 'Expense deleted successfully.']);
    }
}
