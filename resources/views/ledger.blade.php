@extends('layouts.app')

@section('title', 'Customer Ledger')

@section('content')
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1>Ledger: {{ $customer->name }}</h1>
                <p style="color: var(--text-muted);">Tenant ID: {{ $customer->tenant_id }}</p>
            </div>
            <div style="text-align: right;">
                <p style="color: var(--text-muted); font-size: 0.875rem; text-transform: uppercase;">Current Balance</p>
                <h1 style="color: {{ $balance > 0 ? 'var(--danger)' : ($balance < 0 ? 'var(--success)' : 'var(--text-main)') }}">
                    ${{ number_format(abs($balance), 2) }}
                    <small style="font-size: 1rem; color: var(--text-muted);">{{ $balance >= 0 ? 'Owed' : 'Credit' }}</small>
                </h1>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Transaction History</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($history as $entry)
                    <tr>
                        <td>{{ $entry->created_at->format('M d, Y H:i') }}</td>
                        <td>
                            <span class="badge badge-{{ in_array($entry->type, ['ORDER_CHARGE', 'PAYMENT_REVERSAL']) ? 'danger' : 'success' }}">
                                {{ str_replace('_', ' ', $entry->type) }}
                            </span>
                        </td>
                        <td>{{ $entry->description ?: '-' }}</td>
                        <td style="text-align: right; font-weight: 600;">
                            ${{ number_format($entry->amount, 2) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--text-muted); font-style: italic;">No transactions found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card" style="max-width: 500px;">
        <h3>Add Manual Credit</h3>
        <form action="{{ route('web.payments.store') }}" method="POST">
            @csrf
            <input type="hidden" name="tenant_id" value="{{ $customer->tenant_id }}">
            <input type="hidden" name="customer_id" value="{{ $customer->id }}">
            <div class="form-group">
                <label>Amount</label>
                <input type="number" name="amount" step="0.01" required>
            </div>
            <div class="form-group">
                <label>Method</label>
                <select name="method" required>
                    <option value="cash">Cash</option>
                    <option value="transfer">Transfer</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Add Credit</button>
        </form>
    </div>
@endsection
