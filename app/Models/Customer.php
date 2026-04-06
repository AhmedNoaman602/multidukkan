<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasFactory , SoftDeletes;
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'address',
        'price_tier',
    ];
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
