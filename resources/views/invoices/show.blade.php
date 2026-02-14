@extends('layout.app')

@section('title', 'Invoice Details')
@section('page-title', 'Invoice')

@section('content')
<div class="page-header d-print-none">
    <div>
        <h2 class="page-header-title">Invoice #{{ $order->order_id }}</h2>
        <p class="page-header-subtitle">Order ID: {{ $order->id }}</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary" onclick="window.print()">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            Print Invoice
        </button>
        <a href="{{ route('orders.index') }}" class="btn btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to Orders
        </a>
    </div>
</div>

<div class="invoice-container" style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden;">
    <!-- Invoice Header -->
    <div style="padding: 40px; border-bottom: 4px solid var(--accent-primary); background: linear-gradient(to right, var(--bg-card), var(--bg-surface));">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div style="font-size: 28px; font-weight: 800; color: var(--accent-primary); margin-bottom: 8px; letter-spacing: -0.5px;">MULTIDUKKAN</div>
                <div style="color: var(--text-secondary); font-size: 14px; line-height: 1.6;">
                    123 Business Avenue, Tech City<br>
                    contact@multidukkan.com<br>
                    +20 123 456 789
                </div>
            </div>
            <div style="text-align: right;">
                <h1 style="margin: 0; font-size: 32px; font-weight: 700; color: var(--text-primary);">INVOICE</h1>
                <div style="margin-top: 12px; display: flex; flex-direction: column; gap: 4px;">
                    <span style="color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Invoice Number</span>
                    <span style="font-size: 16px; font-weight: 600; color: var(--text-primary);">#{{ $order->order_id }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Invoice Body -->
    <div style="padding: 40px;">
        <!-- Info Grid -->
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 40px; margin-bottom: 48px;">
            <div>
                <h4 style="color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">Bill To</h4>
                <div style="color: var(--text-primary); font-weight: 600; font-size: 16px; margin-bottom: 8px;">{{ $order->customer->name ?? 'Guest Customer' }}</div>
                <div style="color: var(--text-secondary); font-size: 14px; line-height: 1.6;">
                    {{ $order->customer->email ?? 'N/A' }}<br>
                    {{ $order->customer->phone ?? 'N/A' }}<br>
                    Cairo, Egypt
                </div>
            </div>
            <div>
                <h4 style="color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">Ship To</h4>
                <div style="color: var(--text-primary); font-weight: 600; font-size: 16px; margin-bottom: 8px;">{{ $order->customer->name ?? 'Guest Customer' }}</div>
                <div style="color: var(--text-secondary); font-size: 14px; line-height: 1.6;">
                    Shipping Address details<br>
                    Cairo, Egypt
                </div>
            </div>
            <div style="background: var(--bg-card); padding: 20px; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--text-muted); font-size: 13px;">Date Issued:</span>
                        <span style="color: var(--text-primary); font-weight: 500;">{{ $order->created_at->format('M d, Y') }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--text-muted); font-size: 13px;">Payment Status:</span>
                        <span class="badge {{ 
            $order->payment_status == 'paid' ? 'success' : 
            ($order->payment_status == 'partially paid' ? 'warning' : 
            ($order->payment_status == 'unpaid' ? 'danger' : 'secondary'))
        }}" style="font-size: 11px;">{{ strtoupper($order->payment_status) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--text-muted); font-size: 13px;">Amount Due:</span>
                        <span style="color: var(--accent-primary); font-weight: 700;">EGP {{ number_format($order->total, 2) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <div style="margin-bottom: 48px;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: var(--bg-card); text-align: left;">
                        <th style="padding: 16px; color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid var(--border-color);">Product / Description</th>
                        <th style="padding: 16px; color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid var(--border-color); text-align: center;">Qty</th>
                        <th style="padding: 16px; color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid var(--border-color); text-align: right;">Unit Price</th>
                        <th style="padding: 16px; color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid var(--border-color); text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->orderItems as $item)
                    <tr>
                        <td style="padding: 20px 16px; border-bottom: 1px solid var(--border-color);">
                            <div style="color: var(--text-primary); font-weight: 600; margin-bottom: 4px;">{{ $item->product->name }}</div>
                            <div style="color: var(--text-muted); font-size: 12px;">SKU: {{ $item->product->sku }}</div>
                        </td>
                        <td style="padding: 20px 16px; border-bottom: 1px solid var(--border-color); text-align: center; color: var(--text-secondary);">
                            {{ $item->quantity }}
                        </td>
                        <td style="padding: 20px 16px; border-bottom: 1px solid var(--border-color); text-align: right; color: var(--text-secondary);">
                            EGP {{ number_format($item->price, 2) }}
                        </td>
                        <td style="padding: 20px 16px; border-bottom: 1px solid var(--border-color); text-align: right; color: var(--text-primary); font-weight: 600;">
                            EGP {{ number_format($item->subtotal, 2) }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Summary -->
        <div style="display: flex; justify-content: flex-end;">
            <div style="width: 320px;">
                <div style="display: flex; justify-content: space-between; padding: 12px 0; color: var(--text-secondary);">
                    <span>Subtotal</span>
                    <span>EGP {{ number_format($order->orderItems->sum('subtotal'), 2) }}</span>
                </div>
                @if($order->discount_amount > 0)
                <div style="display: flex; justify-content: space-between; padding: 12px 0; color: #10b981;">
                    <span>Discount</span>
                    <span>- EGP {{ number_format($order->discount_amount, 2) }}</span>
                </div>
                @endif
                <div style="display: flex; justify-content: space-between; padding: 12px 0; color: var(--text-secondary);">
                    <span>Tax (0%)</span>
                    <span>EGP 0.00</span>
                </div>
                <div style="margin-top: 16px; padding: 20px 0; border-top: 2px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 18px; font-weight: 700; color: var(--text-primary);">Total</span>
                    <span style="font-size: 24px; font-weight: 800; color: var(--accent-primary);">EGP {{ number_format($order->total, 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div style="padding: 40px; background: var(--bg-card); border-top: 1px solid var(--border-color); text-align: center;">
        <div style="color: var(--text-primary); font-weight: 600; margin-bottom: 8px;">THANK YOU FOR YOUR BUSINESS!</div>
        <div style="color: var(--text-muted); font-size: 13px;">
            If you have any questions about this invoice, please contact our support team.
        </div>
    </div>
</div>

<style>
@media print {
    body {
        background: white !important;
        color: black !important;
    }
    .invoice-container {
        border: none !important;
        background: white !important;
    }
    .d-print-none {
        display: none !important;
    }
    :root {
        --text-primary: #000 !important;
        --text-secondary: #333 !important;
        --bg-surface: #fff !important;
        --border-color: #ddd !important;
        --bg-card: #f9f9f9 !important;
    }
}
</style>
@endsection
