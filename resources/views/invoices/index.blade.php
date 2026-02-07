@extends('layout.app')

@section('title', 'Invoices')
@section('page-title', 'Invoices')

@section('content')
<div class="page-header">
    <div>
        <h2 class="page-header-title">Invoices</h2>
        <p class="page-header-subtitle">Generate and manage invoices</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Create Invoice
        </button>
    </div>
</div>

<!-- Stats Row -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <x-stats-card 
        title="Total Invoices" 
        value="{{ $invoices->count() }}" 
        icon="chart" 
        color="primary"
    />
    <x-stats-card 
        title="Paid" 
        value="{{ $invoices->where('payment_status', 'paid')->sum('total') }}" 
        icon="currency" 
        color="success"
    />
    <x-stats-card 
        title="Unpaid" 
        value="{{ $invoices->where('payment_status', 'unpaid')->sum('total') }}" 
        icon="currency" 
        color="warning"
    />
    <x-stats-card 
        title="Overdue" 
        value="EGP890" 
        icon="currency" 
        color="danger"
    />
</div>

<x-card :padding="false">
    <x-data-table :headers="['Order id', 'Customer', 'Amount', 'Status', 'Actions']">
        @forelse ($invoices as $invoice)
        <tr>
            <td><span style="color: var(--accent-primary); font-weight: 600;">{{ $invoice->order->order_id ?? '#' . $invoice->order_id }}</span></td>
            <td>{{ $invoice->customer_name}}</td>
            <td style="font-weight: 600;">{{ $invoice->total }}</td>
   <td><span class="badge 
        {{ 
            $invoice->payment_status == 'paid' ? 'success' : 
            ($invoice->payment_status == 'pending' ? 'warning' : 
            ($invoice->payment_status == 'unpaid' ? 'danger' : 'secondary'))
        }}">{{ $invoice->payment_status }}</span></td>            <td>
                <div style="display: flex; gap: 8px;">
                    <a href="{{ route('invoices.show', $invoice->id) }}" class="btn btn-icon btn-secondary btn-sm" title="View">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </a>
                    <button class="btn btn-icon btn-secondary btn-sm" title="Download">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                    </button>
                </div>
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="7" style="text-align: center;">No invoices found</td>
        </tr>
        @endforelse
        <!-- <tr>
            <td><span style="color: var(--accent-primary); font-weight: 600;">#INV-002</span></td>
            <td>Sara Ali</td>
            <td style="font-weight: 600;">$89.00</td>
            <td style="color: var(--text-secondary);">Jan 12, 2026</td>
            <td style="color: var(--text-secondary);">Jan 27, 2026</td>
            <td><span class="badge warning">Pending</span></td>
            <td>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-icon btn-secondary btn-sm" title="View">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                    <button class="btn btn-icon btn-secondary btn-sm" title="Download">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                    </button>
                </div>
            </td>
        </tr> -->
        <!-- <tr>
            <td><span style="color: var(--accent-primary); font-weight: 600;">#INV-003</span></td>
            <td>Omar Hassan</td>
            <td style="font-weight: 600;">$567.00</td>
            <td style="color: var(--text-secondary);">Dec 28, 2025</td>
            <td style="color: var(--text-secondary);">Jan 12, 2026</td>
            <td><span class="badge danger">Overdue</span></td>
            <td>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-icon btn-secondary btn-sm" title="View">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                    <button class="btn btn-icon btn-secondary btn-sm" title="Download">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                    </button>
                </div>
            </td>
        </tr> -->
    </x-data-table>
</x-card>
@endsection
