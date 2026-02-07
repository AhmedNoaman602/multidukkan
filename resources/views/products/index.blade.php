@extends('layout.app')

@section('title', 'Products')
@section('page-title', 'Products')

@section('content')
<div class="page-header">
    <div>
        <h2 class="page-header-title">Products</h2>
        <p class="page-header-subtitle">Manage your product catalog</p>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('products.create') }}" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Add Product
        </a>
    </div>
</div>

<x-card :padding="false">
    <x-data-table :headers="['Product', 'SKU', 'Price', 'Cost', 'Stock', 'status' , 'Actions']">
        @forelse ($products as $product)
        <tr>
            <td>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; background: var(--bg-hover); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--text-muted);">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <div>
                        <div style="font-weight: 600;">{{ $product->name }}</div>
                        <!-- <div style="font-size: 12px; color: var(--text-muted);">{{ $product->description }}</div> -->
                    </div>
                </div>
            </td>
            <td style="font-family: monospace; color: var(--text-secondary);">{{ $product->sku }}</td>
            <td style="font-weight: 600;">{{ $product->price }}</td>
            <td>{{ $product->cost }}</td>
            <td>{{ $product->stock_quantity }}</td>
<td>
    @php
        $stockStatus = 'In Stock';
        $badgeClass = 'success';
        
        if ($product->stock_quantity <= 0) {
            $stockStatus = 'Out of Stock';
            $badgeClass = 'danger';
        } elseif ($product->stock_quantity <= ($product->low_stock_alert ?? 10)) {
            $stockStatus = 'Low Stock';
            $badgeClass = 'warning';
        }
    @endphp
    <span class="badge {{ $badgeClass }}" title="Alert at: {{ $product->low_stock_alert ?? 10 }}">
        {{ $stockStatus }}
    </span>
    <!-- @if($product->status != 'active' && $product->status != 'In Stock') -->
        <div style="font-size: 10px; color: var(--text-muted); margin-top: 4px; text-transform: uppercase;">
            {{ $product->status }}
        </div>
    <!-- @endif -->
</td>
            <td>
                <div style="display: flex; gap: 8px;">
                    <a href="{{ route('products.edit', $product->id) }}" class="btn btn-icon btn-secondary btn-sm" title="Edit">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </a>
                    <form action="{{ route('products.destroy', $product->id) }}" method="POST" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete this product?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-icon btn-secondary btn-sm" title="Delete" style="border: none; background: transparent; cursor: pointer;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </form>
                </div>
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="7" style="text-align: center;">No products found</td>
        </tr>
        @endforelse
        <!-- <tr>
            <td>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; background: var(--bg-hover); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--text-muted);">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <div>
                        <div style="font-weight: 600;">Another Product</div>
                        <div style="font-size: 12px; color: var(--text-muted);">Sample description</div>
                    </div>
                </div>
            </td>
            <td style="font-family: monospace; color: var(--text-secondary);">SKU-002</td>
            <td>Clothing</td>
            <td style="font-weight: 600;">$59.00</td>
            <td>120</td>
            <td><span class="badge success">Active</span></td>
            <td>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-icon btn-secondary btn-sm" title="Edit">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </button>
                    <button class="btn btn-icon btn-secondary btn-sm" title="Delete">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>
            </td>
        </tr> -->
    </x-data-table>
</x-card>

<script>
    function statusBadge(status) {
        switch (status) {
            case 'In Stock':
                return '<span class="badge success">In Stock</span>';
            case 'Low Stock':
                return '<span class="badge warning">Low Stock</span>';
            case 'Out of Stock':
                return '<span class="badge danger">Out of Stock</span>';
            default:
                return '<span class="badge secondary">' + status + '</span>';
        }
    }
</script>
@endsection
