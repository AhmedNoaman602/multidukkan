<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasFactory;
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'address',
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
