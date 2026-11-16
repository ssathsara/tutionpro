<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    use BelongsToInstitute;

    /** si = Sinhala, ta = Tamil, en = English */
    public const LANGUAGES = ['si', 'ta', 'en'];

    protected $guarded = ['id', 'institute_id'];

    public static function languageForMedium(?string $medium): string
    {
        return match ($medium) {
            'Sinhala' => 'si',
            'Tamil' => 'ta',
            default => 'en',
        };
    }

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'shuffle_questions' => 'boolean',
        'show_results' => 'boolean',
        'is_published' => 'boolean',
        'duration_minutes' => 'integer',
        'pass_percentage' => 'integer',
        'max_attempts' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('position')->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function isOpen(): bool
    {
        $now = now();

        return $this->is_published
            && (! $this->starts_at || $now->gte($this->starts_at))
            && (! $this->ends_at || $now->lte($this->ends_at));
    }
}
