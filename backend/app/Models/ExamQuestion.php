<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamQuestion extends Model
{
    public const TYPES = ['mcq', 'multi_select', 'true_false', 'short_answer', 'numeric'];

    protected $guarded = ['id'];

    protected $casts = ['options' => 'array', 'correct_answer' => 'array', 'marks' => 'float', 'position' => 'integer'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /** Representation for a student taking the exam: never includes the answer. */
    public function forStudent(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'question' => $this->question,
            'options' => $this->options,
            'marks' => $this->marks,
        ];
    }
}
