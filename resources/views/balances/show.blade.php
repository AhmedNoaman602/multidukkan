@extends('layout.app')

@section('title', $customer->name . ' - Balance')
@section('page-title', 'Customer Balance')

@section('content')
<div class="page-header">
    <div style="display: flex; align-items: center; gap: 20px;">
        <div style="width: 64px; height: 64px; background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-info) 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 24px; color: white; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);">
            {{ $customer->name[0] }}
        </div>
        <div>
            <h2 class="page-header-title">{{ $customer->name }}</h2>
            <p class="page-header-subtitle">Balance & Payment History</p>
        </div>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('balances.create') }}" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 6px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Add Payment
        </a>
        <a href="{{ route('customers.show', $customer->id) }}" class="btn btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 6px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to Profile
        </a>
    </div>
</div>

<!-- Balance Summary Cards -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <x-stats-card 
        title="Current Balance" 
        value="EGP {{ number_format($currentBalance ?? 0, 2) }}" 
        icon="chart" 
        color="danger"
    />
    <x-stats-card 
        title="Total Invoiced" 
        value="EGP {{ number_format($totalInvoiced ?? 0, 2) }}" 
        icon="cart" 
        color="primary"
    />
    <x-stats-card 
        title="Total Paid" 
        value="EGP {{ number_format($totalPaid ?? 0, 2) }}" 
        icon="chart" 
        color="success"
    />
    <x-stats-card 
        title="Last Payment" 
        value="{{ $lastPayment ?? 'N/A' }}" 
        icon="chart" 
        color="warning"
    />
</div>

<div style="display: grid; grid-template-columns: 1fr 350px; gap: 24px;">
    
    <!-- Left Column: Transactions Table -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        
        <!-- Payment Transactions Card -->
        <x-card :padding="false">
            <div style="padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="var(--accent-primary)">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                    </svg>
                    Transaction History
                </h3>
                <div style="display: flex; gap: 8px;">
                    <select class="form-select" style="min-width: 150px; padding: 8px 12px; background: var(--bg-darkest); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-primary); font-size: 13px;">
                        <option value="all">All Transactions</option>
                        <option value="payment">Payments Only</option>
                        <option value="invoice">Invoices Only</option>
                        <option value="refund">Refunds Only</option>
                    </select>
                </div>
            </div>
            
            <x-data-table :headers="['Date', 'Type', 'Reference', 'Description', 'Amount', 'Balance']">
                @forelse($transactions ?? [] as $transaction)
                <tr>
                    <td style="color: var(--text-secondary);">{{ $transaction->created_at->format('M d, Y') }}</td>
                    <td>
                        <span class="badge 
                            {{ 
                                $transaction->type == 'payment' ? 'success' : 
                                ($transaction->type == 'invoice' ? 'primary' : 
                                ($transaction->type == 'refund' ? 'warning' : 'secondary'))
                            }}">{{ ucfirst($transaction->type) }}</span>
                    </td>
                    <td>
                        <span style="color: var(--accent-primary); font-weight: 600;">{{ $transaction->reference }}</span>
                    </td>
                    <td style="color: var(--text-secondary); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        {{ $transaction->description }}
                    </td>
                    <td>
                        @if($transaction->type == 'payment' || $transaction->type == 'refund')
                            <span style="color: #10b981; font-weight: 600;">- EGP {{ number_format($transaction->amount, 2) }}</span>
                        @else
                            <span style="color: #ef4444; font-weight: 600;">+ EGP {{ number_format($transaction->amount, 2) }}</span>
                        @endif
                    </td>
                    <td style="font-weight: 600; color: var(--text-primary);">
                        EGP {{ number_format($transaction->running_balance, 2) }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" style="text-align: center; padding: 60px 40px; color: var(--text-muted);">
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 12px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="opacity: 0.4;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                            </svg>
                            <span>No transactions found for this customer.</span>
                        </div>
                    </td>
                </tr>
                @endforelse
            </x-data-table>
            
            <!-- Pagination -->
            <div style="padding: 16px 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <span style="color: var(--text-muted); font-size: 13px;">Showing 1 to 10 of {{ $transactions->count() ?? 0 }} entries</span>
                <div style="display: flex; gap: 4px;">
                    <button class="btn btn-secondary btn-sm" disabled>Previous</button>
                    <button class="btn btn-primary btn-sm">1</button>
                    <button class="btn btn-secondary btn-sm">2</button>
                    <button class="btn btn-secondary btn-sm">3</button>
                    <button class="btn btn-secondary btn-sm">Next</button>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Right Column: Sidebar -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        
        <!-- Balance Overview Card -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden;">
            <div style="padding: 20px; border-bottom: 1px solid var(--border-color);">
                <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Balance Overview</h3>
            </div>
            <div style="padding: 20px;">
                <!-- Balance Meter -->
                <div style="margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 12px; color: var(--text-muted);">
                        <span>Amount Paid</span>
                        <span>{{ $totalInvoiced > 0 ? round(($totalPaid / $totalInvoiced) * 100) : 0 }}%</span>
                    </div>
                    <div style="height: 8px; background: var(--bg-darkest); border-radius: 4px; overflow: hidden;">
                        <div style="height: 100%; width: {{ $totalInvoiced > 0 ? ($totalPaid / $totalInvoiced) * 100 : 0 }}%; background: linear-gradient(90deg, #10b981, #059669); border-radius: 4px;"></div>
                    </div>
                </div>
                
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; justify-content: space-between; font-size: 13px; padding: 10px; background: var(--bg-darkest); border-radius: var(--radius-sm);">
                        <span style="color: var(--text-muted);">Total Invoiced</span>
                        <span style="color: var(--text-primary); font-weight: 600;">EGP {{ number_format($totalInvoiced ?? 0, 2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px; padding: 10px; background: var(--bg-darkest); border-radius: var(--radius-sm);">
                        <span style="color: var(--text-muted);">Total Paid</span>
                        <span style="color: #10b981; font-weight: 600;">EGP {{ number_format($totalPaid ?? 0, 2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px; padding: 12px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius-sm);">
                        <span style="color: var(--text-primary); font-weight: 500;">Outstanding Balance</span>
                        <span style="color: #ef4444; font-weight: 700;">EGP {{ number_format($currentBalance ?? 0, 2) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Aging Summary Card -->
        <!-- <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden;">
            <div style="padding: 20px; border-bottom: 1px solid var(--border-color);">
                <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Aging Summary</h3>
            </div>
            <div style="padding: 20px;">
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; justify-content: space-between; font-size: 13px;">
                        <span style="color: var(--text-muted);">Current (0-30 days)</span>
                        <span style="color: #10b981; font-weight: 600;">EGP {{ number_format($aging['current'] ?? 0, 2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px;">
                        <span style="color: var(--text-muted);">31-60 days</span>
                        <span style="color: #f59e0b; font-weight: 600;">EGP {{ number_format($aging['30_60'] ?? 0, 2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px;">
                        <span style="color: var(--text-muted);">61-90 days</span>
                        <span style="color: #f97316; font-weight: 600;">EGP {{ number_format($aging['60_90'] ?? 0, 2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px;">
                        <span style="color: var(--text-muted);">Over 90 days</span>
                        <span style="color: #ef4444; font-weight: 600;">EGP {{ number_format($aging['over_90'] ?? 0, 2) }}</span>
                    </div>
                </div>
            </div>
        </div> -->

        <!-- Quick Actions Card -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
            <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 16px; font-weight: 600;">Quick Actions</h3>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <button class="btn btn-secondary" style="width: 100%; justify-content: left;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 8px;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                    Print Statement
                </button>
                <button class="btn btn-secondary" style="width: 100%; justify-content: left;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 8px;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Export as PDF
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

