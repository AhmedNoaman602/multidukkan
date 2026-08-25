<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\BelongsToTenant;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Warehouse;
use App\Support\LocalDateRange;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = auth()->user()->tenant_id;

        return [
            'store_id'             => $this->user()->store_id
                                        ? []
                                        : ['required', 'exists:stores,id', new BelongsToTenant(Store::class, $tenantId)],
            'customer_id'          => ['required', 'exists:customers,id', new BelongsToTenant(Customer::class, $tenantId)],
            'created_by'           => 'nullable|exists:users,id',
            'notes'                => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => ['required', 'exists:products,id', new BelongsToTenant(Product::class, $tenantId)],
            'items.*.warehouse_id' => ['required', 'exists:warehouses,id', new BelongsToTenant(Warehouse::class, $tenantId)],
            'items.*.quantity'     => 'required|integer|min:1',
            'items.*.unit_type' => 'nullable|in:base,secondary',
            'items.*.unit_price' => 'nullable|numeric|min:0|max:99999999.99|decimal:0,2',
            'discount' => 'nullable|numeric|min:0|max:99999999.99|decimal:0,2',
            // manual_total writes to orders.total, which is decimal(12,2) — larger cap.
            'manual_total' => 'nullable|numeric|min:0|max:9999999999.99|decimal:0,2',
            'pay_immediately' => 'nullable|boolean',
            'payment_method'   => 'nullable|string|in:cash,bank_transfer,instapay,vodafone_cash,orange_cash,check',
            // "today" is the SHOP's calendar day. Resolved against the app timezone
            // (UTC) it would reject a genuinely-today order for the first hours of
            // every local morning; resolved against the viewer's zone, a travelling
            // owner would be blocked from dating an order to the day the shop is
            // actually having.
            'order_date' => [
                'required',
                'date',
                'before_or_equal:' . LocalDateRange::today(LocalDateRange::businessTimezone())->toDateString(),
            ],
        ];
    }
}