@extends('layouts.app')

@section('title', 'Overview')

@section('content')
    <div class="card">
        <h1>Dashboard Overview</h1>
        <p style="color: var(--text-muted);">Welcome to MultiDukkan. Here you can manage multiple tenants, their products, and ledger entries.</p>
    </div>

    <div class="grid">
        @foreach($tenants as $tenant)
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div>
                        <h3>{{ $tenant->name }}</h3>
                        <p style="color: var(--text-muted); font-size: 0.875rem;">Tenant ID: {{ $tenant->id }}</p>
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem;">
                    <h4 style="margin-bottom: 0.75rem; font-size: 0.875rem; text-transform: uppercase; color: var(--text-muted);">Stores</h4>
                    @forelse($tenant->stores as $store)
                        <div style="padding: 0.5rem; background: var(--bg); border-radius: 0.5rem; margin-bottom: 0.5rem; display: flex; justify-content: space-between;">
                            <span>{{ $store->name }}</span>
                            <span class="badge badge-success">Active</span>
                        </div>
                    @empty
                        <p style="font-style: italic; color: var(--text-muted);">No stores found.</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
@endsection
