@extends('layout.app')

@section('title', 'Order Details')
@section('page-title', 'Order Details')

@section('content')
<div class="page-header">
    <div>
        <h2 class="page-header-title">{{ $order->id }}</h2>
        <p class="page-header-subtitle">Created on {{ $order->created_at->format('F j, Y') }} at {{ $order->created_at->format('g:i A') }}</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 6px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            Print Invoice
        </button>
        <a href="{{ route('orders.index') }}" class="btn btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 6px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to Orders
        </a>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 350px; gap: 24px;">
    
    <!-- Left Column -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        
        <!-- Order Status Card -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <div style="width: 48px; height: 48px; background: rgba(16, 185, 129, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="#10b981">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Order Status</h3>
                        <p style="margin: 4px 0 0 0; color: var(--text-secondary); font-size: 14px;">Payment received and order completed</p>
                    </div>
                </div>
                <span class="badge 
                {{ 
                    $order->payment_status == 'paid' ? 'success' : 
                    ($order->payment_status == 'partially paid' ? 'warning' : 
                    ($order->payment_status == 'unpaid' ? 'danger' : 'secondary'))
                }}">{{ $order->payment_status }}</span>
            </div>
        </div>

        <!-- Order Items -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
            <h3 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600;">Order Items</h3>
            
            <!-- Column Headers -->
            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 12px; padding: 12px; font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--border-color);">
                <span>Product</span>
                <span style="text-align: center;">Quantity</span>
                <span style="text-align: right;">Unit Price</span>
                <span style="text-align: right;">Subtotal</span>
            </div>

            @foreach($order->orderItems as $item)
            <!-- Product Row -->
            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 12px; padding: 16px 12px; align-items: center; {{ !$loop->last ? 'border-bottom: 1px solid var(--border-color);' : '' }}">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 48px; height: 48px; background: var(--bg-card); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="var(--text-muted)">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <div>
                        <span style="font-weight: 500; color: var(--text-primary);">{{ $item->product->name }}</span>
                        <p style="margin: 4px 0 0 0; font-size: 12px; color: var(--text-muted);">SKU: {{ $item->product->sku }}</p>
                    </div>
                </div>
                <span style="text-align: center; color: var(--text-secondary);">{{ $item->quantity }}</span>
                <span style="text-align: right; color: var(--text-secondary);">EGP {{ number_format($item->price, 2) }}</span>
                <span style="text-align: right; font-weight: 600; color: var(--text-primary);">EGP {{ number_format($item->subtotal, 2) }}</span>
            </div>
            @endforeach
        </div>

        <!-- Payment Summary -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
            <h3 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600;">Payment Summary</h3>
            
            <div style="display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px;">
                <span style="color: var(--text-secondary);">Subtotal</span>
                <span style="color: var(--text-primary);">EGP {{ number_format($order->orderItems->sum('subtotal'), 2) }}</span>
            </div>
            @if($order->discount_amount > 0)
            <div style="display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px;">
                <span style="color: var(--text-secondary);">Discount</span>
                <span style="color: #10b981;">- EGP {{ number_format($order->discount_amount, 2) }}</span>
            </div>
            @endif
            <div style="display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px;">
                <span style="color: var(--text-secondary);">Tax</span>
                <span style="color: var(--text-primary);">EGP 0.00</span>
            </div>
            
            <div style="border-top: 1px dashed var(--border-color); margin: 16px 0;"></div>
            
            <div style="display: flex; justify-content: space-between; font-size: 18px; font-weight: 700;">
                <span>Total</span>
                <span style="color: var(--accent-primary);">EGP {{ number_format($order->total, 2) }}</span>
            </div>

            <div style="border-top: 1px solid var(--border-color); margin-top: 16px; padding-top: 16px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px;">
                    <span style="color: var(--text-secondary);">Payment Method</span>
                    <span style="color: var(--text-primary); font-weight: 500;">{{ $order->payment_method }}</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 14px;">
                    <span style="color: var(--text-secondary);">Payment Status</span>
                    <span class="badge 
                    {{ 
                        $order->payment_status == 'paid' ? 'success' : 
                        ($order->payment_status == 'partially paid' ? 'warning' : 
                        ($order->payment_status == 'unpaid' ? 'danger' : 'secondary'))
                    }}">{{ $order->payment_status }}</span>
                </div>
            </div>
        </div>

    </div>

    <!-- Right Column -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        
        <!-- Customer Information -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Customer</h3>
                <a href="{{ route('customers.show', $order->customer->id) }}" style="font-size: 13px; color: var(--accent-primary); text-decoration: none;">View Profile</a>
            </div>
            
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 18px;">
                    {{ $order->customer->name[0] }}
                </div>
                <div>
                    <span style="font-weight: 600; color: var(--text-primary); display: block;">{{ $order->customer->name }}</span>
                    <span style="font-size: 13px; color: var(--text-muted);">Customer since {{ $order->customer->created_at->format('F j, Y') }}</span>
                </div>
            </div>

            <div style="border-top: 1px solid var(--border-color); padding-top: 16px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="var(--text-muted)">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <span style="font-size: 14px; color: var(--text-secondary);">{{ $order->customer->email }}</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="var(--text-muted)">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                    </svg>
                    <span style="font-size: 14px; color: var(--text-secondary);">{{ $order->customer->phone }}</span>
                </div>
            </div>
        </div>

        <!-- Order Timeline -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
            <h3 style="margin: 0 0 16px 0; font-size: 16px; font-weight: 600;">Order Timeline</h3>
            
            <div style="position: relative; padding-left: 24px;">
                <!-- Timeline Line -->
                <div style="position: absolute; left: 7px; top: 8px; bottom: 8px; width: 2px; background: var(--border-color);"></div>
                
                <!-- Timeline Item 1 -->
                <div style="position: relative; margin-bottom: 20px;">
                    <div style="position: absolute; left: -24px; top: 2px; width: 16px; height: 16px; background: #10b981; border-radius: 50%; border: 3px solid var(--bg-surface);"></div>
                    <div>
                        <span style="font-weight: 500; color: var(--text-primary); font-size: 14px;">Order Completed</span>
                        <p style="margin: 4px 0 0 0; font-size: 12px; color: var(--text-muted);">{{ $order->created_at->format('F j, Y - g:i A') }}</p>
                    </div>
                </div>

                <!-- Timeline Item 2 -->
                <div style="position: relative; margin-bottom: 20px;">
                    <div style="position: absolute; left: -24px; top: 2px; width: 16px; height: 16px; background: var(--accent-primary); border-radius: 50%; border: 3px solid var(--bg-surface);"></div>
                    <div>
                        <span style="font-weight: 500; color: var(--text-primary); font-size: 14px;">Payment Received</span>
                        <p style="margin: 4px 0 0 0; font-size: 12px; color: var(--text-muted);">{{ $order->updated_at->format('F j, Y - g:i A') }}</p>
                    </div>
                </div>

                <!-- Timeline Item 3 -->
                <div style="position: relative; margin-bottom: 20px;">
                    <div style="position: absolute; left: -24px; top: 2px; width: 16px; height: 16px; background: #f59e0b; border-radius: 50%; border: 3px solid var(--bg-surface);"></div>
                    <div>
                        <span style="font-weight: 500; color: var(--text-primary); font-size: 14px;">Order Confirmed</span>
                        <p style="margin: 4px 0 0 0; font-size: 12px; color: var(--text-muted);">{{ $order->updated_at->format('F j, Y - g:i A') }}</p>
                    </div>
                </div>

                <!-- Timeline Item 4 -->
                <div style="position: relative;">
                    <div style="position: absolute; left: -24px; top: 2px; width: 16px; height: 16px; background: var(--text-muted); border-radius: 50%; border: 3px solid var(--bg-surface);"></div>
                    <div>
                        <span style="font-weight: 500; color: var(--text-primary); font-size: 14px;">Order Created</span>
                        <p style="margin: 4px 0 0 0; font-size: 12px; color: var(--text-muted);">{{ $order->created_at->format('F j, Y - g:i A') }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div style="display: flex; flex-direction: column; gap: 12px;">
         
            <a href="{{ route('invoices.show', $order->invoice->id ?? 0) }}" class="btn btn-secondary" style="width: 100%; justify-content: center;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 8px;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                Show Invoice
            </a>
          
        </div>

    </div>
</div>
@endsection
