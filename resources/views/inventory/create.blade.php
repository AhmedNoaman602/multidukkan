@extends('layout.app')

@section('title', 'Create Inventory')

@section('content')
<div class="page-header">
    <div>
        <h2 class="page-header-title">Create New Inventory</h2>
        <p class="page-header-subtitle">Set up a new inventory location or warehouse</p>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('inventory.index') }}" class="btn btn-secondary">
            Cancel
        </a>
    </div>
</div>

<form action="{{ route('inventory.store') }}" method="POST">
    @csrf
    <div style="display: grid; grid-template-columns: 1fr 350px; gap: 24px;">
        
        <!-- Left Column: Inventory Details -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <!-- Basic Information -->
            <x-card :padding="true">
                <h3 style="margin-top: 0; margin-bottom: 20px; font-size: 16px; font-weight: 600;">Basic Information</h3>
                
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Inventory Name</label>
                        <input type="text" name="name" placeholder="e.g. Main Warehouse" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;" required>
                    </div>
                    
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Location</label>
                        <input type="text" name="location" placeholder="e.g. Cairo, Egypt" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;" required>
                    </div>
                </div>
            </x-card>

            <!-- Product Selection -->
            <x-card :padding="true">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Select Products</h3>
                    <span style="font-size: 12px; color: var(--text-muted);">Choose products to assign to this inventory</span>
                </div>

                <div x-data="{ search: '' }" style="display: flex; flex-direction: column; gap: 16px;">
                    <!-- Search Box -->
                    <div style="position: relative;">
                        <input x-model="search" type="text" placeholder="Search products by name or SKU..." style="width: 100%; padding: 10px 12px 10px 40px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                        <div style="position: absolute; left: 12px; top: 10px; color: var(--text-muted);">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>

                    <!-- Products List -->
                    <div style="max-height: 400px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest);">
                        @foreach($products as $product)
                        <div x-show="'{{ strtolower($product->name) }}'.includes(search.toLowerCase()) || '{{ strtolower($product->sku) }}'.includes(search.toLowerCase())" 
                             style="display: flex; align-items: center; gap: 12px; padding: 12px; border-bottom: 1px solid var(--border-color); transition: background 0.2s;"
                             @mouseenter="$el.style.background = 'var(--bg-hover)'"
                             @mouseleave="$el.style.background = 'transparent'">
                            <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" id="product_{{ $product->id }}" style="width: 18px; height: 18px; cursor: pointer; accent-color: var(--accent-primary);">
                            <label for="product_{{ $product->id }}" style="flex: 1; cursor: pointer;">
                                <div style="font-weight: 500; color: var(--text-primary);">{{ $product->name }}</div>
                                <div style="font-size: 12px; color: var(--text-muted);">SKU: {{ $product->sku }} • Stock: {{ $product->stock_quantity }}</div>
                            </label>
                        </div>
                        @endforeach
                    </div>
                </div>
            </x-card>
        </div>

        <!-- Right Column: Actions -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            <x-card :padding="true">
                <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 16px; font-weight: 600;">Actions</h3>
                <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 20px;">Review information before saving the new inventory.</p>
                
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px;">Create Inventory</button>
            </x-card>
        </div>
    </div>
</form>
@endsection
