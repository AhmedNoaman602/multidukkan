@extends('layout.app')

@section('title', 'Create Customer')

@section('content')
<div class="page-header">
    <div>
        <h2 class="page-header-title">Create New Customer</h2>
        <p class="page-header-subtitle">Add a new customer to your database</p>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('customers.index') }}" class="btn btn-secondary">
            Cancel
        </a>
    </div>
</div>

@if ($errors->any())
    <div style="background-color: rgba(239, 68, 68, 0.15); border: 1px solid var(--accent-danger); border-radius: var(--radius-md); padding: 16px; margin-bottom: 24px;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
            <svg style="width: 20px; height: 20px; color: var(--accent-danger);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <h4 style="margin: 0; color: var(--accent-danger); font-size: 14px; font-weight: 600;">Please correct the following errors:</h4>
        </div>
        <ul style="margin: 0; padding-left: 32px; color: var(--text-primary); font-size: 13px;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form action={{route('customers.store')}} method="POST">
    @csrf
    <div style="display: grid; grid-template-columns: 1fr 350px; gap: 24px;">
        
        <!-- Left Column: Customer Information -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <!-- Basic Information Card -->
            <x-card :padding="true">
                <h3 style="margin-top: 0; margin-bottom: 20px; font-size: 16px; font-weight: 600;">Basic Information</h3>
                
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Customer Name</label>
                        <input type="text" name="name" value="{{ old('name') }}" placeholder="e.g. John Doe" style="width: 100%; padding: 10px 12px; border: 1px solid {{ $errors->has('name') ? 'var(--accent-danger)' : 'var(--border-color)' }}; border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                        @error('name')
                            <p style="color: var(--accent-danger); font-size: 12px; margin-top: 4px;">{{ $message }}</p>
                        @enderror
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Email Address</label>
                            <input type="email" name="email" value="{{ old('email') }}" placeholder="e.g. john@example.com" style="width: 100%; padding: 10px 12px; border: 1px solid {{ $errors->has('email') ? 'var(--accent-danger)' : 'var(--border-color)' }}; border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                            @error('email')
                                <p style="color: var(--accent-danger); font-size: 12px; margin-top: 4px;">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Phone Number</label>
                            <input type="text" name="phone" value="{{ old('phone') }}" placeholder="e.g. +1234567890" style="width: 100%; padding: 10px 12px; border: 1px solid {{ $errors->has('phone') ? 'var(--accent-danger)' : 'var(--border-color)' }}; border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                            @error('phone')
                                <p style="color: var(--accent-danger); font-size: 12px; margin-top: 4px;">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Address</label>
                        <textarea name="address" rows="3" placeholder="Enter customer address..." style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none; resize: vertical;">{{ old('address') }}</textarea>
                    </div>
                </div>
            </x-card>

            
            <!-- Financial Information Card -->
            <x-card :padding="true">
                <h3 style="margin-top: 0; margin-bottom: 20px; font-size: 16px; font-weight: 600;">Financial Details</h3>
                
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Price Tier</label>
                        <select name="price_tier" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                            <option value="standard" {{ old('price_tier') == 'standard' ? 'selected' : '' }}>Standard</option>
                            <option value="wholesale" {{ old('price_tier') == 'wholesale' ? 'selected' : '' }}>Wholesale</option>
                            <option value="vip" {{ old('price_tier') == 'vip' ? 'selected' : '' }}>VIP</option>
                        </select>
                    </div>
            </x-card>
        </div>

        <!-- Right Column: Actions & Additional Settings -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <x-card :padding="true">
                <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 16px; font-weight: 600;">Status</h3>
                <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 16px;">Customer account status</p>
                <select name="status" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-darkest); color: var(--text-primary); outline: none;">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </x-card>

            <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px;">Create Customer</button>
                <div style="text-align: center; margin-top: 12px;">
                    <span style="font-size: 12px; color: var(--text-muted);">Confirm customer details before saving</span>
                </div>
            </div>

        </div>
    </div>
</form>
@endsection
