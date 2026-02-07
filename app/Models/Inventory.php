<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    
    protected $fillable = [
        'id',
        'name',
        'location',
        
    ];
    public function products()
    {
        return $this->belongsToMany(Product::class);
    }
}
