@extends('layout.app')

@section('title', 'Orders')
@section('page-title', 'Orders')

@section('content')
<div class="page-header">
    <div>
        <h2 class="page-header-title">Orders</h2>
        <p class="page-header-subtitle">View and manage customer orders</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            New Order
        </button>
    </div>
</div>

<!-- Stats Row -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <x-stats-card 
        title="Total Orders" 
        value="156" 
        icon="cart" 
        color="primary"
    />
    <x-stats-card 
        title="Pending" 
        value="23" 
        icon="chart" 
        color="warning"
    />
    <x-stats-card 
        title="Completed" 
        value="128" 
        icon="chart" 
        color="success"
    />
    <x-stats-card 
        title="Cancelled" 
        value="5" 
        icon="chart" 
        color="danger"
    />
</div>

<x-card :padding="false">
    <x-data-table :headers="['Order ID', 'Customer', 'Products', 'Total', 'Payment', 'Status', 'Date', 'Actions']">
        <tr>
            <td><span style="color: var(--accent-primary); font-weight: 600;">#ORD-001</span></td>
            <td>Ahmed Mohamed</td>
            <td>3 items</td>
            <td style="font-weight: 600;">$245.00</td>
            <td><span class="badge success">Paid</span></td>
            <td><span class="badge success">Completed</span></td>
            <td style="color: var(--text-secondary);">Jan 15, 2026</td>
            <td>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-icon btn-secondary btn-sm" title="View">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </div>
            </td>
        </tr>
        <tr>
            <td><span style="color: var(--accent-primary); font-weight: 600;">#ORD-002</span></td>
            <td>Sara Ali</td>
            <td>1 item</td>
            <td style="font-weight: 600;">$89.00</td>
            <td><span class="badge warning">Pending</span></td>
            <td><span class="badge warning">Pending</span></td>
            <td style="color: var(--text-secondary);">Jan 15, 2026</td>
            <td>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-icon btn-secondary btn-sm" title="View">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </div>
            </td>
        </tr>
        <tr>
            <td><span style="color: var(--accent-primary); font-weight: 600;">#ORD-003</span></td>
            <td>Omar Hassan</td>
            <td>5 items</td>
            <td style="font-weight: 600;">$567.00</td>
            <td><span class="badge success">Paid</span></td>
            <td><span class="badge info">Processing</span></td>
            <td style="color: var(--text-secondary);">Jan 14, 2026</td>
            <td>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-icon btn-secondary btn-sm" title="View">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </div>
            </td>
        </tr>
    </x-data-table>
</x-card>
@endsection
