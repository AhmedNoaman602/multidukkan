@extends('layout.app')

@section('title', 'Inventories')
@section('page-title', 'Inventories')

@section('content')
<div class="page-header">
    <div>
        <h2 class="page-header-title">Inventories</h2>
        <p class="page-header-subtitle">Manage your inventory records and audits</p>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('inventory.create') }}" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Create Inventory
        </a>
    </div>
</div>

<!-- Stats Row -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <x-stats-card 
        title="Products in Store" 
        value="{{ \App\Models\Product::count() }}" 
        icon="package" 
        color="primary"
    />
    <x-stats-card 
        title="Total Inventories" 
        value="{{ $inventories->count() }}" 
        icon="chart" 
        color="success"
    />
</div>

<x-card :padding="false">
    <x-data-table :headers="['Inventory ID', 'Name', 'Location', 'Actions']">
        @forelse ($inventories as $inventory)
        <tr>
            <td>
                <span style="font-weight: 600; color: var(--accent-primary);">{{ $inventory->id }}</span>
            </td>
            <!-- <td style="color: var(--text-secondary);">Jan 25, 2026</td> -->
            <td style="color: var(--text-secondary);">{{ $inventory->name }}</td>
            <!-- <td style="font-weight: 600;">156</td> -->
            <td><span class="badge success">{{$inventory->location}}</span></td>
            <td>
                <div style="display: flex; gap: 8px;">
                    <a href="{{ route('inventory.show', $inventory) }}" class="btn btn-icon btn-secondary btn-sm" title="View Detail">
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
        <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
            No inventories found.
        </td>
    </tr>
    @endforelse
    </x-data-table>
</x-card>

<div style="margin-top: 32px; margin-bottom: 16px;">
    <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: var(--text-primary);">General Stock (Unassigned)</h3>
    <p style="margin: 4px 0 0 0; font-size: 13px; color: var(--text-muted);">Products not currently assigned to any specific warehouse or inventory location</p>
</div>

<x-card :padding="false">
    <x-data-table :headers="['SKU', 'Product Name', 'Price', 'Stock', 'Status', 'Actions']">
       
        @forelse($generalStock as $product)
        <tr>
            <td><span style="color: var(--accent-primary); font-weight: 600;">{{ $product->sku }}</span></td>
            <td style="color: var(--text-primary); font-weight: 500;">{{ $product->name }}</td>
            <td style="font-weight: 600;">EGP {{ number_format($product->price, 2) }}</td>
            <td>
                <div style="display: flex; flex-direction: column; gap: 4px;">
                    <span style="font-weight: 600;">{{ $product->stock_quantity }}</span>
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
                All products are assigned to inventories or no products found.
            </td>
        </tr>
        @endforelse
    </x-data-table>
</x-card>
@endsection

