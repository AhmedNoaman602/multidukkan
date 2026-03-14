<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    /** @use HasFactory<\Database\Factories\StoreFactory> */
    use HasFactory;
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
}
