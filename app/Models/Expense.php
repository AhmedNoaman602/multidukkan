<?php

namespace App\Models;

use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Expense extends Model
{
    use HasFactory, SoftDeletes, ScopedToTenant;

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected $fillable = [
        'tenant_id',
        'store_id',
        'category',
        'amount',
        'description',
        'expense_date',
        'created_by',
        'created_by_name',
    ];

    const CATEGORIES = [
        'SALARIES',
        'RENT',
        'UTILITIES',
        'TRANSPORTATION',
        'INTERNET',
        'MAINTENANCE',
        'SUPPLIES',
        'MISCELLANEOUS'
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
