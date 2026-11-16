<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class ExamAttempt extends Model
{
    use BelongsToInstitute;

    protected $guarded = ['id', 'institute_id'];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'score' => 'float',
        'total_marks' => 'float',
        'percentage' => 'float',
        'question_order' => 'array',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(AttemptAnswer::class);
    }

    public function deadline(): Carbon
    {
        $deadline = $this->started_at->copy()->addMinutes($this->exam->duration_minutes);
        if ($this->exam->ends_at && $this->exam->ends_at->lt($deadline)) {
            $deadline = $this->exam->ends_at->copy();
        }

        return $deadline;
    }
}
