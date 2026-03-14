<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    /** @use HasFactory<\Database\Factories\TenantFactory> */
    use HasFactory;
    protected $fillable = [
        'id',
        'name',
        'created_at',
        'updated_at',
    ];
    public function stores()
    {
        return $this->hasMany(Store::class);
    }
    public function products()
    {
        return $this->hasMany(Product::class);
    }
    public function customers()
    {
        return $this->hasMany(Customer::class);
    }
    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
