@extends('layout.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
<!-- Stats Grid -->
<div class="stats-grid">
    <x-stats-card 
        title="Total Revenue" 
        value="$24,500" 
        icon="currency" 
        color="primary" 
        trend="up" 
        trendValue="+12.5%" 
    />
    <x-stats-card 
        title="Total Orders" 
        value="156" 
        icon="cart" 
        color="success" 
        trend="up" 
        trendValue="+8.2%" 
    />
    <x-stats-card 
        title="Total Customers" 
        value="1,240" 
        icon="users" 
        color="info" 
        trend="up" 
        trendValue="+5.1%" 
    />
    <x-stats-card 
        title="Products" 
        value="432" 
        icon="package" 
        color="warning" 
        trend="down" 
        trendValue="-2.3%" 
    />
</div>

<!-- Main Content Grid -->
<div class="grid-2-1">
    <!-- Chart Section -->
    <x-card title="Sales Overview">
        <x-slot name="actions">
            <select class="btn btn-secondary btn-sm" style="background-color: var(--bg-hover); border: 1px solid var(--border-color); color: var(--text-primary); padding: 6px 12px; border-radius: var(--radius-sm); cursor: pointer;">
                <option>Last 7 Days</option>
                <option>Last 30 Days</option>
                <option>Last 90 Days</option>
            </select>
        </x-slot>
        <div class="chart-placeholder">
            <div style="text-align: center;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-bottom: 12px; opacity: 0.5;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
                <p>Sales chart will appear here</p>
                <p style="font-size: 12px; margin-top: 8px;">(Integrate with Chart.js or similar)</p>
            </div>
        </div>
    </x-card>

    <!-- Quick Actions -->
    <x-card title="Quick Actions">
        <div class="quick-actions">
            <a href="{{ route('orders.index') }}" class="quick-action-btn">
                <div class="quick-action-icon" style="background-color: rgba(16, 185, 129, 0.15); color: var(--accent-success);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                </div>
                <span class="quick-action-text">New Order</span>
            </a>
            <a href="{{ route('products.index') }}" class="quick-action-btn">
                <div class="quick-action-icon" style="background-color: rgba(6, 182, 212, 0.15); color: var(--accent-primary);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>
                <span class="quick-action-text">Add Product</span>
            </a>
            <a href="{{ route('customers.index') }}" class="quick-action-btn">
                <div class="quick-action-icon" style="background-color: rgba(59, 130, 246, 0.15); color: var(--accent-info);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                    </svg>
                </div>
                <span class="quick-action-text">Add Customer</span>
            </a>
            <a href="{{ route('invoices.index') }}" class="quick-action-btn">
                <div class="quick-action-icon" style="background-color: rgba(245, 158, 11, 0.15); color: var(--accent-warning);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <span class="quick-action-text">Create Invoice</span>
            </a>
        </div>
    </x-card>
</div>

<!-- Recent Orders -->
<div style="margin-top: 24px;">
    <x-card title="Recent Orders" :padding="false">
        <x-slot name="actions">
            <a href="{{ route('orders.index') }}" class="btn btn-outline btn-sm">View All</a>
        </x-slot>
        <x-data-table :headers="['Order ID', 'Customer', 'Products', 'Total', 'Status', 'Date']">
            <tr>
                <td><span style="color: var(--accent-primary); font-weight: 600;">#ORD-001</span></td>
                <td>Ahmed Mohamed</td>
                <td>3 items</td>
                <td style="font-weight: 600;">$245.00</td>
                <td><span class="badge success">Completed</span></td>
                <td style="color: var(--text-secondary);">Jan 15, 2026</td>
            </tr>
            <tr>
                <td><span style="color: var(--accent-primary); font-weight: 600;">#ORD-002</span></td>
                <td>Sara Ali</td>
                <td>1 item</td>
                <td style="font-weight: 600;">$89.00</td>
                <td><span class="badge warning">Pending</span></td>
                <td style="color: var(--text-secondary);">Jan 15, 2026</td>
            </tr>
            <tr>
                <td><span style="color: var(--accent-primary); font-weight: 600;">#ORD-003</span></td>
                <td>Omar Hassan</td>
                <td>5 items</td>
                <td style="font-weight: 600;">$567.00</td>
                <td><span class="badge info">Processing</span></td>
                <td style="color: var(--text-secondary);">Jan 14, 2026</td>
            </tr>
            <tr>
                <td><span style="color: var(--accent-primary); font-weight: 600;">#ORD-004</span></td>
                <td>Fatima Youssef</td>
                <td>2 items</td>
                <td style="font-weight: 600;">$156.00</td>
                <td><span class="badge success">Completed</span></td>
                <td style="color: var(--text-secondary);">Jan 14, 2026</td>
            </tr>
            <tr>
                <td><span style="color: var(--accent-primary); font-weight: 600;">#ORD-005</span></td>
                <td>Khaled Ibrahim</td>
                <td>4 items</td>
                <td style="font-weight: 600;">$340.00</td>
                <td><span class="badge danger">Cancelled</span></td>
                <td style="color: var(--text-secondary);">Jan 13, 2026</td>
            </tr>
        </x-data-table>
    </x-card>
</div>
@endsection