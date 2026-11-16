<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    use BelongsToInstitute;

    protected $guarded = ['id', 'institute_id'];

    protected $casts = [
        'monthly_fee' => 'float',
        'is_active' => 'boolean',
        'capacity' => 'integer',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)
            ->withPivot(['enrolled_on', 'discount_percent', 'status'])->withTimestamps();
    }

    public function activeStudents(): BelongsToMany
    {
        return $this->students()->wherePivot('status', 'active');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(TimetableSlot::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $ids = $user->visibleBatchIds();

        return $ids === null ? $q : $q->whereIn('batches.id', $ids);
    }
}
