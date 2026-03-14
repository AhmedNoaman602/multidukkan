@extends('layouts.app')

@section('title', 'Orders & Payments')

@section('content')
    <div class="grid">
        <div class="card">
            <h3>Create New Order</h3>
            <form action="{{ route('web.orders.store') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label>Tenant</label>
                    <select name="tenant_id" onchange="filterStores(this.value)" id="tenant_select" required>
                        <option value="">Select Tenant</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label>Store</label>
                    <select name="store_id" id="store_select" required>
                        <option value="">Select Store</option>
                        @foreach($tenants as $tenant)
                            @foreach($tenant->stores as $store)
                                <option value="{{ $store->id }}" data-tenant="{{ $tenant->id }}" class="store-option" style="display:none">{{ $store->name }}</option>
                            @endforeach
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label>Customer</label>
                    <select name="customer_id" required>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }} (Tenant: {{ $customer->tenant_id }})</option>
                        @endforeach
                    </select>
                </div>

                <div style="border: 1px solid var(--border); padding: 1rem; border-radius: 0.5rem; margin-top: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted);">Order Items</label>
                    <div id="items-container">
                        <div class="grid" style="grid-template-columns: 2fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <select name="items[0][product_id]" required>
                                @foreach($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->name }} (${{ $product->price }})</option>
                                @endforeach
                            </select>
                            <input type="number" name="items[0][quantity]" value="1" min="1" required>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top: 1rem;">Create Order</button>
            </form>
        </div>

        <div class="card">
            <h3>Recent Orders</h3>
            <div style="max-height: 500px; overflow-y: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($orders as $order)
                            <tr>
                                <td>#{{ $order->id }}</td>
                                <td>{{ $order->customer->name }}</td>
                                <td>${{ number_format($order->total_amount, 2) }}</td>
                                <td>
                                    <span class="badge badge-{{ $order->status === 'paid' ? 'success' : ($order->status === 'cancelled' ? 'danger' : 'warning') }}">
                                        {{ ucfirst($order->status) }}
                                    </span>
                                </td>
                                <td style="display: flex; gap: 0.5rem;">
                                    <button class="btn btn-primary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;" onclick="showPaymentModal({{ json_encode($order) }})">Pay</button>
                                    <a href="{{ route('ledger.show', $order->customer_id) }}" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; background: var(--border);">Ledger</a>
                                    @if($order->status !== 'cancelled')
                                        <form action="{{ route('web.orders.delete', $order->id) }}" method="POST" onsubmit="return confirm('Cancel this order?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Cancel</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal for Payment -->
    <div id="payment-modal" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center; padding: 1rem;">
        <div class="card" style="width: 100%; max-width: 400px;">
            <h3>Record Payment</h3>
            <form action="{{ route('web.payments.store') }}" method="POST">
                @csrf
                <input type="hidden" name="tenant_id" id="pay-tenant-id">
                <input type="hidden" name="store_id" id="pay-store-id">
                <input type="hidden" name="order_id" id="pay-order-id">
                <input type="hidden" name="customer_id" id="pay-customer-id">
                
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" name="amount" id="pay-amount" step="0.01" required>
                </div>

                <div class="form-group">
                    <label>Method</label>
                    <select name="method" required>
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="transfer">Transfer</option>
                    </select>
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">Pay Now</button>
                    <button type="button" class="btn" style="background: var(--border); flex: 1;" onclick="closePaymentModal()">Close</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function filterStores(tenantId) {
            const options = document.querySelectorAll('.store-option');
            const select = document.getElementById('store_select');
            select.value = "";
            options.forEach(opt => {
                opt.style.display = opt.dataset.tenant == tenantId ? 'block' : 'none';
            });
        }

        function showPaymentModal(order) {
            document.getElementById('pay-tenant-id').value = order.tenant_id;
            document.getElementById('pay-store-id').value = order.store_id;
            document.getElementById('pay-order-id').value = order.id;
            document.getElementById('pay-customer-id').value = order.customer_id;
            document.getElementById('pay-amount').value = order.total_amount;
            document.getElementById('payment-modal').style.display = 'flex';
        }

        function closePaymentModal() {
            document.getElementById('payment-modal').style.display = 'none';
        }
    </script>
@endsection
