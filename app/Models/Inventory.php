<?php

namespace App\Models;
use App\Models\InventoryTransaction;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    use HasFactory;

    protected $table = 'inventory';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'product_id',
        'quantity',
        'threshold',
    ];
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class);
    }
}
