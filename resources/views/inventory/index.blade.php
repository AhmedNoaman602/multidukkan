@extends('layout.app')

@section('title', 'Inventory')
@section('page-title', 'Inventory')

@section('content')
<div class="page-header">
    <div>
        <h2 class="page-header-title">Inventory</h2>
        <p class="page-header-subtitle">Track and manage your stock levels</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-outline" style="margin-right: 8px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
            </svg>
            Import
        </button>
        <button class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Add Stock
        </button>
    </div>
</div>

<!-- Stats Row -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <x-stats-card 
        title="Total Products" 
        value="432" 
        icon="package" 
        color="primary"
    />
    <x-stats-card 
        title="In Stock" 
        value="398" 
        icon="chart" 
        color="success"
    />
    <x-stats-card 
        title="Low Stock" 
        value="28" 
        icon="chart" 
        color="warning"
    />
    <x-stats-card 
        title="Out of Stock" 
        value="6" 
        icon="chart" 
        color="danger"
    />
</div>

<x-card :padding="false">
    <x-data-table :headers="['Product', 'SKU', 'Location', 'Available', 'Reserved', 'Reorder Level', 'Status', 'Actions']">
        <tr>
            <td>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; background: var(--bg-hover); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--text-muted);">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <span style="font-weight: 600;">Product A</span>
                </div>
            </td>
            <td style="font-family: monospace; color: var(--text-secondary);">SKU-001</td>
            <td style="color: var(--text-secondary);">Warehouse A</td>
            <td style="font-weight: 600; color: var(--accent-success);">120</td>
            <td>15</td>
            <td>20</td>
            <td><span class="badge success">In Stock</span></td>
            <td>
                <button class="btn btn-secondary btn-sm">Adjust</button>
            </td>
        </tr>
        <tr>
            <td>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; background: var(--bg-hover); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--text-muted);">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <span style="font-weight: 600;">Product B</span>
                </div>
            </td>
            <td style="font-family: monospace; color: var(--text-secondary);">SKU-002</td>
            <td style="color: var(--text-secondary);">Warehouse B</td>
            <td style="font-weight: 600; color: var(--accent-warning);">8</td>
            <td>2</td>
            <td>15</td>
            <td><span class="badge warning">Low Stock</span></td>
            <td>
                <button class="btn btn-secondary btn-sm">Adjust</button>
            </td>
        </tr>
        <tr>
            <td>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; background: var(--bg-hover); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--text-muted);">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                    </div>
                    <span style="font-weight: 600;">Product C</span>
                </div>
            </td>
            <td style="font-family: monospace; color: var(--text-secondary);">SKU-003</td>
            <td style="color: var(--text-secondary);">Warehouse A</td>
            <td style="font-weight: 600; color: var(--accent-danger);">0</td>
            <td>0</td>
            <td>10</td>
            <td><span class="badge danger">Out of Stock</span></td>
            <td>
                <button class="btn btn-secondary btn-sm">Adjust</button>
            </td>
        </tr>
    </x-data-table>
</x-card>
@endsection
