<?php

namespace App\Models;
use App\Models\Inventory;
use App\Models\Tenant;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasFactory;
    protected $fillable = [
        'id',
        'tenant_id',
        'store_id',
        'name',
        'address',
        'phone',
        'email',
    ];
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function store()
    {
        return $this->belongsTo(Store::class);
    }
    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }
}
