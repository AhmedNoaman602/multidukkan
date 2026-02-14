<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Order;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;

class BalanceController extends Controller
{
    public function index()
    {
        $totalOutstanding = 0;
        $totalCollected = 0;
        $customersWithBalance = 0;

        $customers = Customer::all()->map(function ($customer) use (
            &$totalOutstanding,
            &$totalCollected,
            &$customersWithBalance
        ) {

            $balance = $customer->balance(); // from ledger

            $customer->computed_balance = $balance;

            if ($balance > 0) {
                $customer->balance_label = 'outstanding';
                $totalOutstanding += $balance;
                $customersWithBalance++;
            } elseif ($balance < 0) {
                $customer->balance_label = 'credit';
                $totalCollected += abs($balance);
            } else {
                $customer->balance_label = 'settled';
            }

            return $customer;
        });

        $totalInvoiced = LedgerEntry::where('account_type', 'customer')
            ->where('type', 'debit')
            ->sum('amount');
            
        $totalPaid = LedgerEntry::where('account_type', 'customer')
            ->where('type', 'credit')
            ->sum('amount');

        return view('balances.index', compact(
            'customers',
            'totalOutstanding',
            'totalCollected',
            'totalInvoiced',
            'totalPaid',
            'customersWithBalance'
        ));
    }

    public function show(Customer $customer)
    {
        // Get transactions from ledger
        $transactions = LedgerEntry::where('account_type', 'customer')
            ->where('account_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Totals
        $totalDebit = $transactions->where('type', 'debit')->sum('amount');
        $totalCredit = $transactions->where('type', 'credit')->sum('amount');

        $currentBalance = $totalDebit - $totalCredit;

        // Last payment
        $lastPayment = $transactions
            ->where('type', 'credit')
            ->first();

        $lastPaymentDate = $lastPayment
            ? $lastPayment->created_at->format('M d, Y')
            : 'N/A';

        return view('balances.show', compact(
            'customer',
            'transactions',
            'totalDebit',
            'totalCredit',
            'currentBalance',
            'lastPaymentDate'
        ));
    }

    public function create(Request $request)
    {
        $customers = Customer::orderBy('name')->get();

        $customerId = $request->query('customer_id');
        $customer = $customerId ? Customer::find($customerId) : null;

        $unpaidOrders = $customer
            ? $customer->orders()
                ->whereIn('payment_status', ['unpaid', 'partially paid'])
                ->get()
            : collect();

        return view('balances.create', compact(
            'customers',
            'customer',
            'unpaidOrders'
        ));
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

        DB::transaction(function () use ($request) {

            LedgerEntry::create([
                'account_type' => 'customer',
                'account_id' => $request->customer_id,
                'type' => 'credit', // payment reduces receivable
                'amount' => $request->amount,
                'description' => 'Customer payment',
                'reference_type' => 'payment',
                'reference_id' => $request->order_id,
                'created_at' => $request->payment_date,
            ]);
        });

        // Update order status
        if ($request->order_id) {

            $order = Order::findOrFail($request->order_id);

            $totalDebit = LedgerEntry::where('account_type', 'customer')
                ->where('account_id', $order->customer_id)
                ->where('reference_type', 'order')
                ->where('reference_id', $order->id)
                ->where('type', 'debit')
                ->sum('amount');

            $totalCredit = LedgerEntry::where('account_type', 'customer')
                ->where('account_id', $order->customer_id)
                ->where('reference_type', 'payment')
                ->where('reference_id', $order->id)
                ->where('type', 'credit')
                ->sum('amount');

            if ($totalCredit >= $totalDebit) {
                $order->update(['payment_status' => 'paid']);
            } elseif ($totalCredit > 0) {
                $order->update(['payment_status' => 'partially paid']);
            } else {
                $order->update(['payment_status' => 'unpaid']);
            }
        }

        return redirect()->route('balances.index')
            ->with('success', 'Payment recorded successfully');
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
