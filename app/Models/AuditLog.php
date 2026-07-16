<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $casts = [
        'changes' => 'array',
    ];

    // See LedgerEntry::$dateFormat — same reasoning.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'auditable_type',
        'auditable_id',
        'action',
        'changes',
    ];

    const UPDATED_AT = null;
}
