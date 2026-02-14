@extends('layout.app')

@section('title', 'Create Order')

@section('content')
<div class="page-header">
    <div>
        <h2 class="page-header-title">Create New Order</h2>
        <p class="page-header-subtitle">Create a new order for a customer</p>
    </div>
    <div class="page-header-actions">
        <a href="{{ route('orders.index') }}" class="btn btn-secondary">
            Cancel
        </a>
    </div>
</div>

<x-card :padding="true">
    <form action="{{ route('orders.store') }}" method="POST">
        @csrf
    <div style="display: grid; grid-template-columns: 1fr 350px; gap: 24px;">
        
        <!-- Left Column: Order Items -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <!-- Products Section -->
            <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Order Items</h3>
                    <button type="button" onclick="addProductRow(0)" class="btn btn-secondary btn-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 4px;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Add Product
                    </button>
                </div>

                <!-- Column Headers -->
                <div style="display: flex; justify-content: space-between; gap: 12px; padding: 8px 8px; font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--border-color); margin-bottom: 8px;">
                    <span>Product</span>
                    <span style="text-align: center;">Qty</span>
                    <span style="text-align: right;">Price</span>
                    <span style="text-align: right;">Subtotal</span>
                    <span></span>
                </div>

                <div id="products-container">
                    <!-- Product Row Template -->
                    <div class="product-row" data-row="0" style="display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 8px; background: #0f172a; border-radius: var(--radius-sm); margin-bottom: 8px;">
                        <div class="product-select">
                            <select name="products[0][product_id]" class="product-dropdown" onchange="handleProductChange(0)" style="width: 100%; padding: 8px 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-input); color: var(--text-secondary);">
                                <option value="">Select Product</option>
                                @foreach($products as $product)
                                    <option value="{{ $product->id }}"
                                        data-price="{{ $product->price }}"
                                        data-cost="{{ $product->cost ?? 0 }}"
                                        data-stock="{{ $product->stock_quantity }}"
                                        data-unit="{{ $product->unit ?? '' }}">
                                        {{ $product->name }} ({{ $product->sku }}) - Stock: {{ $product->stock_quantity }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="product-quantity">
                            <input type="number" name="products[0][quantity]" placeholder="Qty" min="1" value="1" oninput="Subtotal()" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-input); color: var(--text-primary); text-align: center;">
                        </div>
                        <div class="product-price">
                            <input type="number" name="products[0][price]"  step="0.01" readonly style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: #475569; color: var(--text-secondary); text-align: right;">
                        </div> 
                        <!-- <div class="product-cost">
                            <input type="number" name="products[0][cost]" step="0.01" readonly style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: #475569; color: var(--text-secondary); text-align: right;">
                        </div> -->
                        <div class="product-subtotal" style="min-width: 100px; text-align: right;">
                            <span class="subtotal-display" style="font-weight: 600; color: var(--text-primary);">EGP 0.00</span>
                        </div>
                        <div class="product-actions">
                            <button type="button" onclick="removeProductRow(0)" style="background: none; border: none; color: #fff; cursor: pointer; font-size: 20px; padding: 0; line-height: 1; opacity: 0.6; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.6'">×</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Customer & Totals -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <!-- Customer Selection -->
            <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
                <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 16px; font-weight: 600;">Customer</h3>
                <div style="margin-bottom: 12px;">
                    <x-searchable-select 
                        name="customer_id" 
                        :options="$customers" 
                        placeholder="Search and select customer..." 
                    />
                </div>
                <a href="{{ route('customers.create') }}" class="btn btn-secondary btn-sm" style="width: 100%;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right: 6px;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    New Customer
                </a>
            </div>

            <!-- Payment & Summary -->
            <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px;">
                <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 16px; font-weight: 600;">Order Summary</h3>
                
                <div style="display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; color: var(--text-secondary);">
                    <span>Subtotal</span>
                    <span id="summary-subtotal">EGP 0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 14px; color: var(--text-secondary);">
                    <span>Discount</span>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <span>EGP</span>
                        <input type="number" id="discount-input" name="discount" value="0" min="0" step="0.01" oninput="calculateTotals()" style="width: 80px; padding: 6px 8px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-input); color: var(--text-primary); text-align: right;">
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; color: var(--text-secondary);">
                    <span>Tax</span>
                    <span>EGP 0.00</span>
                </div>
                
                <div style="border-top: 1px dashed var(--border-color); margin: 16px 0;"></div>
                
                <div style="display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 18px; font-weight: 700;">
                    <span>Total</span>
                    <span id="summary-total"></span>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--text-secondary);">Payment Method</label>
                    <select name="payment_method" style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--bg-input); color: var(--text-primary);">
                        <option value="cash">Cash</option>
                        <option value="card">Credit Card</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px;">Create Order</button>
            </div>

        </div>
    </div>
</form>
</x-card>

<script>
    let rowIndex = 0;

   function Subtotal() {
    calculateTotals();
}

   function calculateTotals() {
    const rows = document.querySelectorAll('.product-row');
    let grandTotal = 0;

    rows.forEach(row => {
        // 1. Get values for THIS row
        const price = parseFloat(row.querySelector('.product-price input').value) || 0;
        const quantity = parseFloat(row.querySelector('.product-quantity input').value) || 0;
        
        // 2. Calculate the subtotal for THIS row
        const rowSubtotal = price * quantity;
        
        // 3. Update the display for THIS row
        const subtotalDisplay = row.querySelector('.subtotal-display');
        if (subtotalDisplay) {
            subtotalDisplay.textContent = 'EGP ' + rowSubtotal.toFixed(2);
        }
        
        // 4. Add this row's subtotal to the Grand Total
        grandTotal += rowSubtotal;
    });

    // 5. Get the discount value
    const discountInput = document.getElementById('discount-input');
    const discount = parseFloat(discountInput ? discountInput.value : 0) || 0;

    // 6. Calculate the final total
    const finalTotal = Math.max(0, grandTotal - discount);

    // 7. Update the Order Summary at the bottom
    document.querySelector('#summary-subtotal').textContent = 'EGP ' + grandTotal.toFixed(2);
    document.querySelector('#summary-total').textContent = 'EGP ' + finalTotal.toFixed(2);
}

    function handleProductChange(rowIndex) {
        // 1. Find the specific row using the row index
        const row = document.querySelector(`.product-row[data-row="${rowIndex}"]`);
        
        // 2. Find the select element within that row to get the selected option
        const select = row.querySelector('.product-dropdown');
        const selectedOption = select.options[select.selectedIndex];
        
        // 3. Retrieve the price and cost from the data attributes we set in Blade
        const price = selectedOption.getAttribute('data-price');
        // const cost = selectedOption.getAttribute('data-cost');
        
        // 4. Find the price and cost input fields for this specific row
        const priceInput = row.querySelector(`input[name="products[${rowIndex}][price]"]`);
        // const costInput = row.querySelector(`input[name="products[${rowIndex}][cost]"]`);
        
        // 5. Update the input values
        if (priceInput) priceInput.value = price || '';
        // if (costInput) costInput.value = cost || '';
        
        // Optional: If you have a function to calculate totals, call it here
        // calculateRowTotal(rowIndex);
        Subtotal();
    }

 function addProductRow() {
    // 1. Get the container and the row we want to copy (the first one)
    const container = document.getElementById('products-container');
    const rowToClone = container.querySelector('.product-row'); // Always clone the first row
    
    // 2. Increment our global counter so we have a fresh index (0 becomes 1, then 2, etc.)
    rowIndex++;
    const newIndex = rowIndex;

    // 3. Clone the row
    const newRow = rowToClone.cloneNode(true);

    // 4. Update the data-row attribute

    newRow.setAttribute('data-row', newIndex);

    // 5. THE MAGIC LINE: Replace all "[0]" with "[newIndex]" and "(0)" with "(newIndex)"
    // This updates all names (products[0] -> products[1]) and all function calls at once.
    newRow.innerHTML = newRow.innerHTML.replace(/\[0\]/g, `[${newIndex}]`).replace(/\(0\)/g, `(${newIndex})`);

    // 6. Clear values so the new row is empty
    const select = newRow.querySelector('.product-dropdown');
    if (select) select.value = '';
    
    newRow.querySelectorAll('input').forEach(input => {
        input.value = '';
    });

    const quantityInput = newRow.querySelector('.product-quantity input');
   if(quantityInput) quantityInput.value = '1';
    // 7. Clear the subtotal display (it's a span, not an input)
    const subtotalSpan = newRow.querySelector('.subtotal-display');
    if (subtotalSpan) subtotalSpan.textContent = 'EGP 0.00';

    // 8. Add it to the screen
    container.appendChild(newRow);
}

    function removeProductRow(rowIndex) {
        const row = document.querySelector(`.product-row[data-row="${rowIndex}"]`);
        row.remove();
    }
</script>
@endsection


