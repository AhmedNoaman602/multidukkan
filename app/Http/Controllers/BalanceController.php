<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Balance;
use App\Models\Customer;
use App\Models\Order;
class BalanceController extends Controller
{
    public function index()
    {
        $customers = Customer::with('balances')->get();
        
        $totalOutstanding = $customers->sum(fn($c) => $c->outstanding_balance);
        $totalCollected = Balance::where('type', 'payment')->sum('amount');
        $customersWithBalance = $customers->filter(fn($c) => $c->outstanding_balance > 0)->count();
        
        return view('balances.index', compact('customers', 'totalOutstanding', 'totalCollected', 'customersWithBalance'));
    }

    public function show(Customer $customer){
        // Get all transactions for this customer, ordered by date
        $transactions = $customer->balances()->orderBy('created_at', 'desc')->get();
        
        // Calculate totals
        $totalInvoiced = $transactions->where('type', 'invoice')->sum('amount');
        $totalPaid = $transactions->where('type', 'payment')->sum('amount') + 
                     $transactions->where('type', 'refund')->sum('amount');
        $currentBalance = $totalInvoiced - $totalPaid;
        
        // Get last payment date
        $lastPaymentRecord = $transactions->where('type', 'payment')->first();
        $lastPayment = $lastPaymentRecord ? $lastPaymentRecord->created_at->format('M d, Y') : 'N/A';
        
        // Calculate aging summary
        // $aging = Balance::calculateAging($transactions);
        
        return view('balances.show', compact(
            'customer',
            'transactions',
            'totalInvoiced',
            'totalPaid',
            'currentBalance',
            'lastPayment',
            // 'aging'
        ));
    }

    public function create(Request $request)
    {
        $customers = Customer::orderBy('name')->get();
        $customerId = $request->query('customer_id');
        $customer = $customerId ? Customer::find($customerId) : null;
        $unpaidOrders = $customer ? $customer->orders()->where('payment_status', 'unpaid')->orderBy('order_id')->get() : collect();
        return view('balances.create', compact('customers' , 'customer' , 'unpaidOrders'));
    }
    public function store(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'order_id' => 'nullable|exists:orders,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'required|string',
        ]);

        $customer = Customer::findOrFail($request->customer_id);
        
        // Calculate running balance
        $lastTransaction = Balance::where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->first();
        
        $currentRunningBalance = $lastTransaction ? $lastTransaction->running_balance : 0;
        $newRunningBalance = $currentRunningBalance - $request->amount;

        Balance::create([
            'customer_id' => $request->customer_id,
            'order_id' => $request->order_id,
            'type' => 'payment',
            'amount' => $request->amount,
            'payment_method' => $request->payment_method,
            'reference' => $request->reference ?? Balance::generateReference('payment'),
            'description' => $request->notes ?? 'Customer payment',
            'notes' => $request->notes,
            'running_balance' => $newRunningBalance,
            'created_at' => $request->payment_date,
        ]);
        if ($request->order_id) {
            $order = Order::findOrFail($request->order_id);
            if($order->totalPaid >= $order->total){
                $order->update([
                    'payment_status' => 'paid',
                ]);
            }elseif($order->totalPaid < $order->total && $order->totalPaid > 0){
                $order->update([
                    'payment_status' => 'partially paid',
                ]);
            }else{
                $order->update([
                    'payment_status' => 'unpaid',
                ]);
            }
        }

        return redirect()->route('balances.index')->with('success', 'Payment recorded successfully');
    }
    public function edit(Balance $balance)
    {
        return view('balances.create', compact('balance'));
    }
    public function update(Request $request, Balance $balance)
    {
        $balance->update($request->all());
        return redirect()->route('balances.index');
    }
    public function destroy(Balance $balance)
    {
        $balance->delete();
        return redirect()->route('balances.index');
    }
}
