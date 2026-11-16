<?php

namespace App\Models\Concerns;

use App\Models\Institute;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Row-level multi-tenancy: automatically filters queries to the current
 * institute and stamps institute_id on create.
 */
trait BelongsToInstitute
{
    public static function bootBelongsToInstitute(): void
    {
        static::addGlobalScope('institute', function (Builder $builder) {
            $tenant = app(Tenant::class);
            if ($tenant->has()) {
                $builder->where($builder->getModel()->getTable().'.institute_id', $tenant->id());
            }
        });

        static::creating(function ($model) {
            $tenant = app(Tenant::class);
            if (empty($model->institute_id) && $tenant->has()) {
                $model->institute_id = $tenant->id();
            }
        });
    }

    public function institute(): BelongsTo
    {
        return $this->belongsTo(Institute::class);
    }
}
