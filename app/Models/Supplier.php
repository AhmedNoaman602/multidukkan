<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    /** @use HasFactory<\Database\Factories\SupplierFactory> */
    use HasFactory , SoftDeletes, ScopedToTenant;
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'phone',
        'email',
        'address',
        'area',
        'notes',
    ];
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }
    public function supplierPayments()
    {
        return $this->hasMany(SupplierPayment::class);
    }
    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class , 'entity_id')
                    ->where('entity_type' , 'supplier');
    }

public function products()
{
    return $this->belongsToMany(Product::class, 'supplier_products')
    ->withPivot('cost_price', 'last_purchase_price', 'last_purchased_at', 'is_preferred', 'notes')
    ->withTimestamps();
}

public function syncProducts(array $pivotData): void
{
    foreach ($pivotData as $productId => $attributes) {
        $attributes['tenant_id'] = $this->tenant_id;
        $pivotData[$productId] = $attributes;
    }

    $this->products()->syncWithoutDetaching($pivotData);
}
}
