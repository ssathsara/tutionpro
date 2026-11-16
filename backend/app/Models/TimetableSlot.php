<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimetableSlot extends Model
{
    use BelongsToInstitute;

    protected $guarded = ['id', 'institute_id'];

    protected $casts = ['day_of_week' => 'integer'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
