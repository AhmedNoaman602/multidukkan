@extends('layouts.app')

@section('title', 'Product Management')

@section('content')
    <div class="grid">
        <div class="card">
            <h3>Create New Product</h3>
            <p style="color: var(--text-muted); margin-bottom: 1rem;">Test SKU uniqueness across tenants.</p>
            
            <form action="{{ route('web.products.store') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label>Tenant</label>
                    <select name="tenant_id" required>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" name="name" placeholder="e.g. Premium Coffee" required>
                </div>

                <div class="form-group">
                    <label>SKU</label>
                    <input type="text" name="sku" placeholder="e.g. COF-001" required>
                </div>

                <div class="form-group">
                    <label>Price</label>
                    <input type="number" name="price" step="0.01" placeholder="9.99" required>
                </div>

                <button type="submit" class="btn btn-primary">Create Product</button>
            </form>
        </div>

        <div class="card">
            <h3>Recent Products</h3>
            <table>
                <thead>
                    <tr>
                        <th>Tenant</th>
                        <th>Name</th>
                        <th>SKU</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($products as $product)
                        <tr>
                            <td>{{ $product->tenant->name }}</td>
                            <td>{{ $product->name }}</td>
                            <td><code>{{ $product->sku }}</code></td>
                            <td>${{ number_format($product->price, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
