<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Student extends Model
{
    use BelongsToInstitute;

    protected $guarded = ['id', 'institute_id'];

    protected $casts = ['date_of_birth' => 'date:Y-m-d'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function batches(): BelongsToMany
    {
        return $this->belongsToMany(Batch::class)
            ->withPivot(['enrolled_on', 'discount_percent', 'status'])->withTimestamps();
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'guardian_student', 'student_id', 'parent_id')
            ->withPivot('relationship')->withTimestamps();
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(FeeInvoice::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    /** Users who should hear about this student: the student and their guardians. */
    public function notifiableUsers(): Collection
    {
        return collect([$this->user])->merge($this->guardians)->filter()->unique('id')->values();
    }

    /** Guardians only (for parent-targeted alerts such as absence). */
    public function guardianUsers(): Collection
    {
        return $this->guardians()->get();
    }
}
