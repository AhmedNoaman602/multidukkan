<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Http\Resources\CustomerResource;
use Illuminate\Support\Facades\DB;
use App\Services\LedgerService;
use App\Http\Requests\RefundCustomerRequest;
use Illuminate\Validation\ValidationException;
class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */

    public function __construct(protected LedgerService $ledgerService) {}

    public function index(Request $request)
{
    $this->authorize('viewAny', Customer::class);

    $user = auth()->user();

    $query = Customer::where('tenant_id', $user->tenant_id)
        ->where('is_walk_in', false)
        ->when($request->search, fn($q) => $q
            ->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%")
                  ->orWhere('code', 'like', "%{$request->search}%");
            })
        )
        ->orderBy('code', 'asc');

    $customerIds = (clone $query)->select('id');

    $debits = DB::table('ledger_entries')
        ->whereIn('customer_id', $customerIds)
        ->whereIn('type', ['ORDER_CHARGE', 'CREDIT_CONSUMED','REFUND'])
        ->sum('amount');

    $credits = DB::table('ledger_entries')
        ->whereIn('customer_id', $customerIds)
        ->whereIn('type', ['PAYMENT', 'CREDIT_APPLY', 'REVERSAL'])
        ->sum('amount');

    $totalOutstanding = max(0, round($debits - $credits, 2));

    $customers = $query->paginate(20);

    return response()->json([
        'data' => CustomerResource::collection($customers)->resolve(),
        'meta' => [
            'current_page' => $customers->currentPage(),
            'last_page'    => $customers->lastPage(),
            'total'        => $customers->total(),
        ],
        'stats' => [
            'total_customers'   => $customers->total(),
            'total_outstanding' => $totalOutstanding,
        ],
    ]);
}

    private function generateCustomerCode(int $tenantId): string
{
    $last = Customer::where('tenant_id', $tenantId)
        ->whereNotNull('code')
        ->orderByDesc('id')
        ->value('code');

    $lastNumber = $last ? (int) substr($last, 2) : 0;
    $next = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);

    return "C-{$next}";
}

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Customer::class);
        
        $user = auth()->user();

        $validated = $request->validate([
            'name' => 'required',
            'phone' => 'required',
            'address' => 'nullable',
            'price_tier' => 'nullable|in:default,a,b,c,d,e',
        ]);

        $customer = Customer::create([
            'tenant_id'           => $user->tenant_id,
            'created_by_store_id' => $user->store_id,
            'name'                => $validated['name'],
            'phone'               => $validated['phone'],
            'address'             => $validated['address'] ?? null,
            'price_tier'          => $validated['price_tier'] ?? 'default',
            'code'                => $this->generateCustomerCode($user->tenant_id),
        ]);

        return (new CustomerResource($customer))
                ->response()
                ->setStatusCode(201);    
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer)
    {
        $this->authorize('view', $customer);
        
        if ($customer->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return new CustomerResource($customer);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);
        
        if ($customer->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'phone'      => 'sometimes|string|max:20',
            'address'    => 'nullable|string|max:255',
            'price_tier' => 'sometimes|nullable|in:default,a,b,c,d,e',
        ]);        
        
        $customer->update($validated);
        return new CustomerResource($customer);
    }

    /**
     * Remove the specified resource from storage.
     */
   public function destroy(Customer $customer)
{
    $this->authorize('delete', $customer);

    if ($customer->tenant_id !== auth()->user()->tenant_id) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    try {
        $customer->delete();
        return response()->json(['message' => 'Customer deleted successfully.']);
    } catch (ValidationException $e) {
        return response()->json(['message' => $e->errors()['customer'][0]],422);
    }
}

    public function refund(RefundCustomerRequest $request, Customer $customer)
{
    $this->authorize('update', $customer);

    if ($customer->tenant_id !== auth()->user()->tenant_id) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $validated = $request->validated();

    $entry = $this->ledgerService->issueRefund([
        'tenant_id'   => $customer->tenant_id,
        'customer_id' => $customer->id,
        'store_id'    => auth()->user()->store_id,
        'amount'      => $validated['amount'],
        'method'      => $validated['method'],
        'notes'       => $validated['notes'] ?? null,
        'order_id'    => $validated['order_id'] ?? null,
        'payment_id_target'  => $validated['payment_id_target'] ?? null, 

    ]);

    $newBalance = $this->ledgerService->getBalance(
        $customer->tenant_id,
        $customer->id
    );

    return response()->json([
        'message'         => 'Refund issued successfully.',
        'refunded_amount' => $validated['amount'],
        'new_balance'     => $newBalance,
        'entry_id'        => $entry->id,
    ]);
}
}
