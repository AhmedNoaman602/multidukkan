<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait ScopedToTenant
{
    public static function bootScopedToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $tenantId = static::currentTenantId();

            if ($tenantId === null) {
                return;
            }

            $query->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId);
        });

        static::creating(function (Model $model) {
            if ($model->tenant_id === null) {
                $model->tenant_id = static::currentTenantId();
            }
        });
    }

    protected static function currentTenantId(): ?int
    {
        return auth()->user()?->tenant_id;
    }
}
