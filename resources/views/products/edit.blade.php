@extends('layout.app')

@section('title', 'Edit Product - ' . $product->name)

@section('content')
<div class="page-header">
    <div>
        <h2 class="page-header-title">Edit Product</h2>
        <p class="page-header-subtitle">Update product details and inventory assignments</p>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('products.index') }}" class="btn btn-secondary">
            Cancel
        </a>
    </div>
</div>

<form action="{{ route('products.update', $product->id) }}" method="POST">
    @csrf
    @method('PUT')
    <div style="display: grid; grid-template-columns: 1fr 350px; gap: 24px;">
        
        <!-- Left Column: Product Information -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <!-- Basic Information Card -->
            <x-card :padding="true">
                <h3 style="margin-top: 0; margin-bottom: 20px; font-size: 16px; font-weight: 600;">Basic Information</h3>
                
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Product Name</label>
                        <input type="text" name="name" value="{{ $product->name }}" placeholder="e.g. Wireless Headphones" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">SKU (Stock Keeping Unit)</label>
                            <input type="text" name="sku" value="{{ $product->sku }}" placeholder="e.g. WH-001" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Unit</label>
                            <select name="unit" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                                <option value="pcs" {{ $product->unit == 'pcs' ? 'selected' : '' }}>Pieces (pcs)</option>
                                <option value="kg" {{ $product->unit == 'kg' ? 'selected' : '' }}>Kilograms (kg)</option>
                                <option value="m" {{ $product->unit == 'm' ? 'selected' : '' }}>Meters (m)</option>
                                <option value="box" {{ $product->unit == 'box' ? 'selected' : '' }}>Boxes</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Description</label>
                        <textarea name="description" rows="4" placeholder="Describe your product..." style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none; resize: vertical;">{{ $product->description }}</textarea>
                    </div>
                </div>
            </x-card>

            <!-- Pricing & Cost Card -->
            <x-card :padding="true">
                <h3 style="margin-top: 0; margin-bottom: 20px; font-size: 16px; font-weight: 600;">Pricing & Costs</h3>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Selling Price (EGP)</label>
                        <input type="number" name="price" value="{{ $product->price }}" step="0.01" placeholder="0.00" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Cost Price (EGP)</label>
                        <input type="number" name="cost" value="{{ $product->cost }}" step="0.01" placeholder="0.00" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                    </div>
                </div>
            </x-card>

            <!-- Inventory Stock Control -->
            <x-card :padding="true">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Stock Control</h3>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="track_stock" {{ ($product->track_stock ?? true) ? 'checked' : '' }} style="width: 16px; height: 16px; accent-color: var(--accent-primary);">
                        <label for="track_stock" style="font-size: 13px; color: var(--text-secondary);">Track Inventory</label>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Current Stock Level</label>
                        <input type="number" name="stock_quantity" value="{{ $product->stock_quantity }}" placeholder="0" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Low Stock Alert</label>
                        <input type="number" name="low_stock_alert" value="{{ $product->low_stock_alert }}" placeholder="5" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                    </div>
                </div>
            </x-card>
        </div>

        <!-- Right Column: Settings & Inventory Selection -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <!-- Status Card -->
            <!-- <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
                <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 16px; font-weight: 600;">Product Status</h3>
                <select name="status" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                    <option value="active" {{ $product->status == 'active' ? 'selected' : '' }}>Active</option>
                    <option value="draft" {{ $product->status == 'draft' ? 'selected' : '' }}>Draft</option>
                    <option value="archived" {{ $product->status == 'archived' ? 'selected' : '' }}>Archived</option>
                </select>
            </div> -->

            <!-- Inventory Selection -->
            <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
                <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 16px; font-weight: 600;">Inventory</h3>
                <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 16px;">Select the inventory where this product will be stored</p>
                
                <x-searchable-select 
                    name="inventory_id" 
                    :options="$inventories" 
                    placeholder="Search inventory..." 
                    :required="false"
                    :value="$product->inventories->first()?->id ?? ''"
                    :valueLabel="$product->inventories->first()?->name ?? ''"
                />
                <p style="font-size: 11px; color: var(--text-muted); margin-top: 8px;">Leave empty to add to <strong>General Stock (Unassigned)</strong></p>
                @error('inventory_id')
                    <span style="color: #ef4444; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                @enderror
                
                <a href="{{ route('inventory.index') }}" class="btn btn-secondary btn-sm" style="width: 100%; margin-top: 16px; text-decoration: none; justify-content: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 4px;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Manage Inventories
                </a>
            </div>

            <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px;">Update Product</button>
                <div style="text-align: center; margin-top: 12px;">
                    <span style="font-size: 12px; color: var(--text-muted);">Last updated: {{ $product->updated_at->diffForHumans() }}</span>
                </div>
            </div>

        </div>
    </div>
</form>
@endsection
