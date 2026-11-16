<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use BelongsToInstitute;

    public const AUDIENCES = ['all', 'students', 'parents', 'teachers'];

    protected $guarded = ['id', 'institute_id'];

    protected $casts = ['is_pinned' => 'boolean', 'send_sms' => 'boolean', 'published_at' => 'datetime', 'notified_at' => 'datetime'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
