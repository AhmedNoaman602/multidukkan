@extends('layout.app')

@section('title', $customer->name . ' - Profile')

@section('content')
<div class="page-header">
    <div style="display: flex; align-items: center; gap: 20px;">
        <div style="width: 64px; height: 64px; background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-info) 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 24px; color: white; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);">
            {{ $customer->name[0] }}
        </div>
        <div>
            <h2 class="page-header-title">{{ $customer->name }}</h2>
            <p class="page-header-subtitle">Customer since {{ $customer->created_at->format('M Y') }} • {{ ucfirst($customer->price_tier) }} Tier</p>
        </div>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('orders.create', ['customer_id' => $customer->id]) }}" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 6px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            New Order
        </a>
        <a href="{{ route('customers.edit', $customer->id) }}" class="btn btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 6px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            Edit
        </a>
        <a href="{{ route('balances.show', $customer->id) }}" class="btn btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 6px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            Balance
        </a>
    </div>
</div>

<!-- Stats Row -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <x-stats-card 
        title="Total Spent" 
        value="EGP {{ number_format($customer->orders()->where('payment_status', 'paid')->sum('total'), 2) }}"        icon="cart" 
        color="success"
    />
    <x-stats-card 
        title="Total Orders" 
        value="{{ $customer->total_orders }}" 
        icon="chart" 
        color="primary"
    />
    <x-stats-card 
        title="Current Balance" 
        value="EGP {{ number_format($customer->orders()->where('payment_status', '!=', 'paid')->sum('total'), 2) }}" 
        icon="chart" 
        color="danger"
    />
    <x-stats-card 
        title="Avg. Order Value" 
        value="EGP {{ $customer->total_orders > 0 ? number_format($customer->orders()->where('payment_status', 'paid')->sum('total') / $customer->total_orders, 2) : '0.00' }}" 
        icon="chart" 
        color="warning"
    />
</div>

<div style="display: grid; grid-template-columns: 1fr 350px; gap: 24px;">
    
    <!-- Left Column: Primary Content -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        
        <!-- Customer Information Card -->
        <x-card :padding="true">
            <h3 style="margin-top: 0; margin-bottom: 20px; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="var(--accent-primary)">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Detailed Information
            </h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                <div>
                    <div style="margin-bottom: 16px;">
                        <span style="font-size: 12px; color: var(--text-muted); display: block; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Email Address</span>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="color: var(--text-primary); font-weight: 500;">{{ $customer->email ?? 'Not provided' }}</span>
                        </div>
                    </div>
                    <div>
                        <span style="font-size: 12px; color: var(--text-muted); display: block; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Phone Number</span>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="color: var(--text-primary); font-weight: 500;">{{ $customer->phone ?? 'Not provided' }}</span>
                        </div>
                    </div>
                </div>
                <div>
                    <div style="margin-bottom: 16px;">
                        <span style="font-size: 12px; color: var(--text-muted); display: block; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">Physical Address</span>
                        <span style="color: var(--text-primary); font-weight: 500; line-height: 1.5;">{{ $customer->address ?? 'No address on file' }}</span>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card :padding="false">
            <div style="padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Recent Orders</h3>
                <a href="{{ route('orders.index', ['customer_id' => $customer->id]) }}" style="font-size: 13px; color: var(--accent-primary); text-decoration: none; font-weight: 500;">View All Orders</a>
            </div>
            
            <x-data-table :headers="['Order ID', 'Date', 'Items', 'Total', 'Status', 'Actions']">
                @forelse($customer->orders->take(5) as $order)
                <tr>
                    <td><span style="color: var(--accent-primary); font-weight: 600;">{{ $order->order_id }}</span></td>
                    <td style="color: var(--text-secondary);">{{ $order->created_at->format('M d, Y') }}</td>
                    <td>{{ $order->quantity }} items</td>
                    <td style="font-weight: 600;">EGP {{ number_format($order->total, 2) }}</td>
                    <td>
                        <span class="badge 
                            {{ 
                                $order->payment_status == 'completed' ? 'success' : 
                                ($order->payment_status == 'pending' ? 'warning' : 
                                ($order->payment_status == 'cancelled' ? 'danger' : 'secondary'))
                            }}">{{ ucfirst($order->payment_status) }}</span>
                    </td>
                    <td>
                        <a href="{{ route('orders.show', $order->id) }}" class="btn btn-icon btn-secondary btn-sm" title="View Order">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                        No orders found for this customer.
                    </td>
                </tr>
                @endforelse
            </x-data-table>
        </x-card>
    </div>

    <!-- Right Column: Sidebar -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        
        <!-- Status & Account Card -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
            <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 16px; font-weight: 600;">Account Status</h3>
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding: 12px; background: var(--bg-darkest); border-radius: var(--radius-sm);">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 8px; height: 8px; background: #10b981; border-radius: 50%;"></div>
                    <span style="font-weight: 500; font-size: 14px;">{{ ucfirst($customer->status ?? 'active') }}</span>
                </div>
                <span style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Verified</span>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <div style="display: flex; justify-content: space-between; font-size: 13px;">
                    <span style="color: var(--text-muted);">Price Tier</span>
                    <span style="color: var(--text-primary); font-weight: 600;">{{ ucfirst($customer->price_tier) }}</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 13px;">
                    <span style="color: var(--text-muted);">Loyalty Points</span>
                    <span style="color: var(--accent-primary); font-weight: 600;">1,250 PTS</span>
                </div>
            </div>
        </div>

        <!-- Quick Actions Card -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
            <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 16px; font-weight: 600;">Quick Actions</h3>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <button class="btn btn-secondary" style="width: 100%; justify-content: left;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 8px;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    Send Email
                </button>
                <button class="btn btn-secondary" style="width: 100%; justify-content: left;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 8px;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Account Statement
                </button>
                <button class="btn btn-secondary" style="width: 100%; justify-content: left; color: #ef4444; border-color: rgba(239, 68, 68, 0.2);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 8px;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                    </svg>
                    Block Account
                </button>
            </div>
        </div>

    </div>
</div>
@endsection
