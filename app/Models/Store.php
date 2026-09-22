<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    /** @use HasFactory<\Database\Factories\StoreFactory> */
    use HasFactory, ScopedToTenant;
    protected $fillable = [
        'tenant_id',
        'name',
        'address',
        'phone',
    ];
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
    public function warehouses()
    {
        return $this->hasMany(Warehouse::class);
    }
    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class);
    }
    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
    public function users()
    {
        return $this->hasMany(User::class);
    }
}
