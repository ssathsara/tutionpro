<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
{
    use BelongsToInstitute;

    public const TYPES = ['video', 'note', 'tutorial'];

    /**
     * Attachments live on the private disk (storage/app/private) and are only served through
     * the authenticated GET /api/lessons/{id}/file endpoint, never by a public URL.
     */
    public const FILE_DISK = 'local';

    protected $guarded = ['id', 'institute_id'];

    protected $casts = ['is_published' => 'boolean', 'position' => 'integer'];

    protected $appends = ['has_file'];

    protected $hidden = ['file_path'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function getHasFileAttribute(): bool
    {
        return (bool) $this->file_path;
    }
}
