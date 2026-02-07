@extends('layout.app')

@section('title', 'Inventory: ' . $inventory->name)

@section('content')
<div class="page-header">
    <div style="display: flex; align-items: center; gap: 20px;">
        <div style="width: 64px; height: 64px; background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-info) 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 24px; color: white; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);">
            {{ strtoupper(substr($inventory->name, 0, 1)) }}
        </div>
        <div>
            <h2 class="page-header-title">{{ $inventory->name }}</h2>
            <p class="page-header-subtitle">Location: {{ $inventory->location }} • Created on {{ $inventory->created_at->format('M d, Y') }}</p>
        </div>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('inventory.edit', $inventory) }}" class="btn btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 6px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            Edit Inventory
        </a>
    </div>
</div>

<!-- Stats Row -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <x-stats-card 
        title="Total Products" 
        value="{{ $inventory->products->count() }}" 
        icon="package" 
        color="primary"
    />
    <x-stats-card 
        title="Total Items Stock" 
        value="{{ $inventory->products->sum('stock_quantity') }}" 
        icon="chart" 
        color="success"
    />
    <x-stats-card 
        title="Low Stock Items" 
        value="{{ $inventory->products->where('stock_quantity', '<=', 5)->count() }}" 
        icon="chart" 
        color="danger"
    />
</div>

<div style="display: grid; grid-template-columns: 1fr; gap: 24px;">
    <!-- Products associated with this inventory -->
    <x-card :padding="false">
        <div style="padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Products in this Inventory</h3>
        </div>
        
        <x-data-table :headers="['SKU', 'Product Name', 'Price', 'Stock', 'Status', 'Actions']">
            @forelse($inventory->products as $product)
            <tr>
                <td><span style="color: var(--accent-primary); font-weight: 600;">{{ $product->sku }}</span></td>
                <td style="color: var(--text-primary); font-weight: 500;">{{ $product->name }}</td>
                <td style="font-weight: 600;">EGP {{ number_format($product->price, 2) }}</td>
                <td>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <span style="font-weight: 600;">{{ $product->stock_quantity }}</span>
                        @if($product->stock_quantity <= 5)
                            <span style="font-size: 10px; color: #f87171; font-weight: 600; text-transform: uppercase;">Low Stock</span>
                        @endif
                    </div>
                </td>
                <td>
                    <span class="badge {{ $product->stock_quantity > 0 ? 'success' : 'danger' }}">
                        {{ $product->stock_quantity > 0 ? 'In Stock' : 'Out of Stock' }}
                    </span>
                </td>
                <td>
                    <button class="btn btn-icon btn-secondary btn-sm" title="View Product">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </button>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                    No products found in this inventory.
                </td>
            </tr>
            @endforelse
        </x-data-table>
    </x-card>
</div>
@endsection
