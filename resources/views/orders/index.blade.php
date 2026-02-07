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
        <!-- <button class="btn btn-primary" onclick="window.location.href = '{{ route('orders.create') }}'">
           
            New Order
        </button> -->
        <a class="btn btn-primary" href="{{route('orders.create')}}">
         <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>    
        New Order</a>
    </div>
</div>

<!-- Stats Row -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <x-stats-card 
        title="Total Orders" 
        value="{{ $orders->count() }}" 
        icon="cart" 
        color="primary"
    />
    <x-stats-card 
        title="Paid" 
        value="{{ $orders->where('payment_status', 'paid')->count() }}" 
        icon="chart" 
        color="success"
    />
      <x-stats-card 
        title="partially paid" 
        value="{{ $orders->where('payment_status', 'partially paid')->count() }}" 
        icon="chart" 
        color="warning"
    />
    <x-stats-card 
        title="Unpaid" 
        value="{{ $orders->where('payment_status', 'unpaid')->count() }}" 
        icon="chart" 
        color="danger"
    />
</div>

<x-card :padding="false">
    <x-data-table :headers="['Order ID', 'Customer', 'Products', 'Total', 'Payment_status', 'Date' , 'Actions']">
        @forelse ($orders as $order)
        <tr>
            <td><span style="color: var(--accent-primary); font-weight: 600;">{{ $order->order_id }}</span></td>
            <td>{{ $order->customer->name ?? 'N/A' }}</td>
            <td>{{ $order->quantity }} items</td>
            <td style="font-weight: 600;">EGP {{ number_format($order->total, 2) }}</td>
            <td><span class="badge 
        {{ 
            $order->payment_status == 'paid' ? 'success' : 
            ($order->payment_status == 'partially paid' ? 'warning' : 
            ($order->payment_status == 'unpaid' ? 'danger' : 'secondary'))
        }}">{{ $order->payment_status }}</span></td>
            <td style="color: var(--text-secondary);">{{ $order->created_at->format('Y-m-d') }}</td>
            <td>
                <div style="display: flex; justify-content: space-around; align-items: center; gap: 8px;">
                    <a class="btn btn-icon btn-secondary btn-sm" title="View" href="{{ route('orders.show', $order->id) }}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        <a href="{{ route('invoices.show', $order->invoice->id ?? 0) }}">View Invoice</a>
                    </a>
                </div>
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="7" style="text-align: center;">No orders found</td>
        </tr>
        @endforelse
        <!-- <tr>
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
        </tr> -->
        <!-- <tr>
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
        </tr> -->
    </x-data-table>
</x-card>
@endsection
