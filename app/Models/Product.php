<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory, ScopedToTenant;
    protected $fillable = [
        'tenant_id',
        'name',
        'sku',
        'description',
        'description_ar',
        'description_en',
        'cost_price',
        'price',
        'price_a',
        'price_b',
        'price_c',
        'price_d',
        'price_e',
        'unit',
        'secondary_unit',
        'conversion_factor',
        'opening_quantity',
    ];

    protected $attributes = [
        'unit' => 'pcs',
    ];
    
    protected $casts = [
        'price' => 'decimal:2',
        ];
    
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }
    public function purchaseOrderItems()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
    public function suppliers()
{
    return $this->belongsToMany(Supplier::class, 'supplier_products')
                ->withPivot('cost_price', 'last_purchase_price', 'last_purchased_at', 'is_preferred', 'notes')
                ->withTimestamps();
}

public function syncSuppliers(array $supplierIds): void
{
    $ids = array_values(array_unique(array_map('intval', $supplierIds)));

    $payload = [];
    foreach ($ids as $id) {
        $payload[$id] = ['tenant_id' => $this->tenant_id];
    }

    $this->suppliers()->syncWithoutDetaching($payload);

    $stale = $this->suppliers()->pluck('suppliers.id')->diff($ids);
    if ($stale->isNotEmpty()) {
        $this->suppliers()->detach($stale->all());
    }
}
}
