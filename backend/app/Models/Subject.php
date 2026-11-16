<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use BelongsToInstitute;

    protected $guarded = ['id', 'institute_id'];

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }
}
