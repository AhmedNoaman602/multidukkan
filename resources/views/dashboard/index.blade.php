@extends('layout.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
<!-- Stats Grid -->
<div class="stats-grid">
    <x-stats-card 
        title="Total Revenue" 
        value="EGP {{ $orders->where('payment_status', 'paid')->sum('total') }}" 
        icon="currency" 
        color="primary" 
        trend="up" 
        trendValue="+12.5%" 
    />
    <x-stats-card 
        title="Total Orders" 
        value="{{ $orders->count() }}" 
        icon="cart" 
        color="success" 
        trend="up" 
        trendValue="+8.2%" 
    />
    <x-stats-card 
        title="Total Customers" 
        value="{{ $customers->count() }}" 
        icon="users" 
        color="info" 
        trend="up" 
        trendValue="+5.1%" 
    />
    <x-stats-card 
        title="Products" 
        value="{{ $products->count() }}" 
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
            <a href="{{ route('orders.create') }}" class="quick-action-btn">
                <div class="quick-action-icon" style="background-color: rgba(16, 185, 129, 0.15); color: var(--accent-success);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                </div>
                <span class="quick-action-text">New Order</span>
            </a>
            <a href="{{ route('products.create') }}" class="quick-action-btn">
                <div class="quick-action-icon" style="background-color: rgba(6, 182, 212, 0.15); color: var(--accent-primary);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>
                <span class="quick-action-text">Add Product</span>
            </a>
            <a href="{{ route('customers.create') }}" class="quick-action-btn">
                <div class="quick-action-icon" style="background-color: rgba(59, 130, 246, 0.15); color: var(--accent-info);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                    </svg>
                </div>
                <span class="quick-action-text">Add Customer</span>
            </a>
            <a href="{{ route('inventory.create') }}" class="quick-action-btn">
                <div class="quick-action-icon" style="background-color: rgba(245, 158, 11, 0.15); color: var(--accent-warning);">
                   <svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                </div>
                <span class="quick-action-text">Create Inventory</span>
            </a>
        </div>
    </x-card>
</div>

<!-- Recent Orders -->
<div style="margin-top: 24px;">
    <x-card :padding="false">
    <x-data-table :headers="['Order ID', 'Customer', 'Products', 'Total', 'Payment_status', 'Date' , 'Actions']">
        @forelse ($latestOrders as $order)
        <tr>
            <td><span style="color: var(--accent-primary); font-weight: 600;">{{ $order->order_id }}</span></td>
            <td>{{ $order->customer->name ?? 'N/A' }}</td>
            <td>{{ $order->quantity }} items</td>
            <td style="font-weight: 600;">EGP {{ number_format($order->total, 2) }}</td>
            <td><span class="badge 
        {{ 
            $order->payment_status == 'paid' ? 'success' : 
            ($order->payment_status == 'pending' ? 'warning' : 
            ($order->payment_status == 'unpaid' ? 'danger' : 'secondary'))
        }}">{{ $order->payment_status }}</span></td>
            <td style="color: var(--text-secondary);">{{ $order->created_at->format('Y-m-d') }}</td>
            <td>
                <div style="display: flex; gap: 8px;">
                    <a class="btn btn-icon btn-secondary btn-sm" title="View" href="{{ route('orders.show', $order->id) }}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
</a>
                </div>
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="7" style="text-align: center;">No orders found</td>
        </tr>
        @endforelse
    </x-data-table>
</x-card>
</div>
@endsection