@extends('layout.app')

@section('title', 'Record Payment')
@section('page-title', 'Record Payment')

@section('content')
<div class="page-header">
    <div>
        <h2 class="page-header-title">Record Payment</h2>
        <p class="page-header-subtitle">Record a new payment for a customer</p>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('balances.index') }}" class="btn btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 6px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to Balances
        </a>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 400px; gap: 24px;">
    <!-- Payment Form -->
    <x-card>
        <div style="padding: 20px; border-bottom: 1px solid var(--border-color);">
            <h3 style="margin: 0; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="var(--accent-primary)">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                Payment Details
            </h3>
        </div>
        <div style="padding: 24px;">
            <form action="{{ route('balances.store') }}" method="POST" id="paymentForm">
                @csrf
                <!-- Customer Selection -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; font-weight: 500; color: var(--text-secondary);">Select Customer *</label>
                    <select name="customer_id" id="customerSelect" onchange="updateSidebar(this.value)" style="width: 100%; padding: 12px; background: var(--bg-darkest); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-primary); font-size: 14px;">
                        <option value="">Choose a customer...</option>
                        @foreach($customers as $cust)
                            <option value="{{ $cust->id }}" {{ (isset($customer) && $customer->id == $cust->id) ? 'selected' : '' }}>
                                {{ $cust->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; font-weight: 500; color: var(--text-secondary);">Select Order *</label>
                    <select name="order_id" id="order_id" onchange="updateAmount(this)" style="width: 100%; padding: 12px; background: var(--bg-darkest); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-primary); font-size: 14px;">
                        <option value="">Choose an order...</option>
                        @if(isset($unpaidOrders))
                            @foreach($unpaidOrders as $ord)
                                <option value="{{ $ord->id }}" data-total="{{ $ord->total }}" {{ (isset($selected_order_id) && $selected_order_id == $ord->id) ? 'selected' : '' }}>
                                    {{ $ord->order_id }} (EGP {{ number_format($ord->total, 2) }})
                                </option>
                            @endforeach
                        @endif
                    </select>
                </div>

                <!-- Payment Amount -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; font-weight: 500; color: var(--text-secondary);">Payment Amount *</label>
                    <div style="position: relative;">
                        <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted);">EGP</span>
                        <input type="number" name="amount" step="0.01" placeholder="0.00" style="width: 100%; padding: 12px 12px 12px 48px; background: var(--bg-darkest); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-primary); font-size: 14px;">
                    </div>
                </div>
                
                <!-- Payment Date -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; font-weight: 500; color: var(--text-secondary);">Payment Date *</label>
                    <input type="date" name="payment_date" style="width: 100%; padding: 12px; background: var(--bg-darkest); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-primary); font-size: 14px;">
                </div>
                
                <!-- Payment Method -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; font-weight: 500; color: var(--text-secondary);">Payment Method *</label>
                    <select name="payment_method" style="width: 100%; padding: 12px; background: var(--bg-darkest); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-primary); font-size: 14px;">
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="card">Credit/Debit Card</option>
                        <option value="check">Check</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <!-- Reference Number -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; font-weight: 500; color: var(--text-secondary);">Reference Number</label>
                    <input type="text" name="reference" placeholder="e.g., Receipt #, Check #" style="width: 100%; padding: 12px; background: var(--bg-darkest); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-primary); font-size: 14px;">
                </div>
                
                <!-- Notes -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 13px; font-weight: 500; color: var(--text-secondary);">Notes</label>
                    <textarea name="notes" rows="3" placeholder="Optional payment notes..." style="width: 100%; padding: 12px; background: var(--bg-darkest); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-primary); font-size: 14px; resize: vertical;"></textarea>
                </div>
                
                <!-- Submit Button -->
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <a href="{{ route('balances.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 6px;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Record Payment
                    </button>
                </div>
            </form>
        </div>
    </x-card>

    <!-- Customer Balance Preview (Sidebar) -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        <!-- Selected Customer Info -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden;">
            <div style="padding: 20px; border-bottom: 1px solid var(--border-color);">
                <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Customer Balance</h3>
            </div>
            <div style="padding: 20px;">
                @if ($customer)
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
                    <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-info) 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 18px; color: white;">
                        {{ $customer->name[0] }}
                    </div>
                    <div>
                        <div style="font-weight: 600; font-size: 15px;">{{ $customer->name }}</div>
                        <div style="font-size: 12px; color: var(--text-muted);">{{ $customer->email }}</div>
                    </div>
                </div>
                
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; justify-content: space-between; font-size: 13px; padding: 10px; background: var(--bg-darkest); border-radius: var(--radius-sm);">
                        <span style="color: var(--text-muted);">Total Invoiced</span>
                        <span style="color: var(--text-primary); font-weight: 600;">EGP {{ number_format($customer->total_invoiced, 2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px; padding: 10px; background: var(--bg-darkest); border-radius: var(--radius-sm);">
                        <span style="color: var(--text-muted);">Total Paid</span>
                        <span style="color: #10b981; font-weight: 600;">EGP {{ number_format($customer->total_paid, 2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 13px; padding: 12px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius-sm);">
                        <span style="color: var(--text-primary); font-weight: 500;">Outstanding Balance</span>
                        <span style="color: #ef4444; font-weight: 700;">EGP {{ number_format($customer->outstanding_balance, 2) }}</span>
                    </div>
                </div>
                @else
                <div style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="opacity: 0.4; margin-bottom: 12px;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <p style="margin: 0; font-size: 13px;">Select a customer to view their balance</p>
                </div>
                @endif
        </div>

        <!-- Quick Tips -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
            <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="var(--accent-info)">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Quick Tips
            </h3>
            <ul style="margin: 0; padding-left: 20px; color: var(--text-muted); font-size: 13px; line-height: 1.8;">
                <li>You can record partial payments</li>
                <li>A reference number helps track payments</li>
                <li>Use notes for any additional details</li>
                <li>The balance updates automatically</li>
            </ul>
        </div>
    </div>
</div>

<script>
    function updateSidebar(customerId) {
        if (!customerId) {
            window.location.href = "{{ route('balances.create') }}";
            return;
        }
        
        window.location.href = "{{ route('balances.create') }}?customer_id=" + customerId;
    }

    function updateAmount(selectElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const total = selectedOption.getAttribute('data-total');
        
        if (total) {
            document.querySelector('input[name="amount"]').value = total;
        }
    }
</script>
@endsection
