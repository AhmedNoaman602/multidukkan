@extends('layout.app')

@section('title', 'Customer Balances')
@section('page-title', 'Balances')

@section('content')
<div class="page-header">
    <div>
        <h2 class="page-header-title">Customer Balances</h2>
        <p class="page-header-subtitle">Track and manage all customer balances</p>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('balances.create') }}" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Record Payment
        </a>
    </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <x-stats-card 
        title="Total Debit" 
        value="EGP {{ number_format($totalOutstanding ?? 0, 2) }}" 
        icon="chart" 
        color="danger"
    />
    <x-stats-card 
        title="Total Credit" 
        value="EGP {{ number_format($totalCollected ?? 0, 2) }}" 
        icon="chart" 
        color="success"
    />
    <x-stats-card 
        title="Customers with Balance" 
        value="{{ $customersWithBalance ?? 0 }}" 
        icon="users" 
        color="warning"
    />
    <x-stats-card 
        title="Overdue (90+ days)" 
        value="EGP 0.00" 
        icon="chart" 
        color="primary"
    />
</div>

<x-card :padding="false">
    <div style="padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="var(--accent-primary)">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
            </svg>
            All Customer Balances
        </h3>
        <div style="display: flex; gap: 8px;">
            <select class="form-select" style="min-width: 150px; padding: 8px 12px; background: var(--bg-darkest); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-primary); font-size: 13px;">
                <option value="all">All Customers</option>
                <option value="with_balance">With Balance</option>
                <option value="no_balance">No Balance</option>
            </select>
            <input type="text" placeholder="Search customers..." style="padding: 8px 12px; background: var(--bg-darkest); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-primary); font-size: 13px; min-width: 200px;">
        </div>
    </div>
    
    <x-data-table :headers="['Customer', 'Total Invoiced', 'Total Paid', 'Outstanding Balance', 'Last Payment', 'Actions']">
        {{-- Sample row - will be replaced with actual data --}}
        @if($customers->isEmpty())
        <tr>
            <td colspan="6" style="text-align: center; padding: 60px 40px; color: var(--text-muted);">
                <div style="display: flex; flex-direction: column; align-items: center; gap: 12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="opacity: 0.4;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    <span>No balance records found.</span>
                </div>
            </td>
        </tr>
        @elseif($customers->isNotEmpty())
        @foreach ($customers as $customer)
        <tr>
            <td>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-info) 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; color: white;">
                        {{ $customer->name[0] }}
                    </div>
                    <div>
                        <div style="font-weight: 600;">{{ $customer->name }}</div>
                        <div style="font-size: 12px; color: var(--text-muted);">ID: #{{ $customer->id }}</div>
                    </div>
                </div>
            </td>
            <td style="font-weight: 600; color: var(--text-secondary);">EGP {{ number_format($customer->total_invoiced, 2) }}</td>
            <td style="font-weight: 600; color: #10b981;">EGP {{ number_format($customer->total_paid, 2) }}</td>


<td style="font-weight: 600;">
   @if ($customer->balance_label === 'outstanding')
    <span style="color:#ef4444">
        EGP {{ number_format($customer->computed_balance, 2) }}
    </span>
    <div class="text-muted text-xs">Outstanding</div>

@elseif ($customer->balance_label === 'credit')
    <span style="color:#10b981">
        Credit: EGP {{ number_format(abs($customer->computed_balance), 2) }}
    </span>
    <div class="text-muted text-xs">Overpaid</div>

@else
    <span class="text-muted">Settled</span>
@endif

</td>
            <td style="color: var(--text-muted);">{{ $customer->last_payment }}</td>
            <td>
                <div style="display: flex; gap: 8px;">
                    <a href="{{ route('balances.show', $customer->id) }}" class="btn btn-icon btn-secondary btn-sm" title="View Details">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </a>
                    <a href="{{ route('balances.create') }}?customer_id={{ $customer->id }}" class="btn btn-primary btn-sm" title="Record Payment">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Pay
                    </a>
                </div>
            </td>
        </tr>
      
        @endforeach
        @endif
    </x-data-table>
    
    <!-- Pagination -->
    <div style="padding: 16px 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <span style="color: var(--text-muted); font-size: 13px;">Showing {{ $customers->count() }} entries</span>
        <div style="display: flex; gap: 4px;">
            <button class="btn btn-secondary btn-sm" disabled>Previous</button>
            <button class="btn btn-secondary btn-sm" disabled>Next</button>
        </div>
    </div>
</x-card>
@endsection
