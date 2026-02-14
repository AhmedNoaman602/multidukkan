<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $fillable = [
        'account_type',
        'account_id',
        'type', 
        'amount',
        'description',
        'reference_type',
        'reference_id',
    ];
    public $timestamps = true;
}
