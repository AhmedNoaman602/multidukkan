<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Services\LedgerService;
use App\Http\Requests\StoreCreditRequest;
use Illuminate\Support\Facades\Auth;

class LedgerEntryController extends Controller
{
     public function __construct(protected LedgerService $ledger) {}

    public function balance(Customer $customer)
    {
        $this->authorize('view', $customer);
        
        $balance = $this->ledger->getBalance($customer->tenant_id, $customer->id);

        return response()->json([
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'balance'     => $balance,
            'status'      => $balance > 0 ? 'owes' : ($balance < 0 ? 'credit' : 'settled'),
        ], 200);
    }

    public function history(Customer $customer)
    {
        $this->authorize('view', $customer);
        
        $history = $this->ledger->getHistory($customer->tenant_id, $customer->id);
        $balance = $this->ledger->getBalance($customer->tenant_id, $customer->id);
        return response()->json([
            'customer_id'   => $customer->id,
            'customer_name' => $customer->name,
            'status'        => $balance > 0 ? 'owes' : ($balance < 0 ? 'credit' : 'settled'),
            'history' => $history,
            'balance' => $balance,
        ], 200);
    }

    public function addCredit(Customer $customer, StoreCreditRequest $request)
{
    $this->authorize('create', Customer::class);
        
    $user = auth()->user();
    $entry = $this->ledger->addCredit([
        'tenant_id'   => $user->tenant_id,
        'customer_id' => $customer->id,
        'store_id'    => $user->store_id,
        'amount'      => $request->amount,
        'description' => $request->description,
    ]);

    $balance = $this->ledger->getBalance($user->tenant_id, $customer->id);

    return response()->json([
        'message'     => 'Credit added successfully',
        'entry'       => $entry,
        'new_balance' => $balance,
    ], 201);
}

}
