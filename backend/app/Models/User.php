<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use BelongsToInstitute, HasApiTokens, HasFactory, Notifiable;

    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN = 'institute_admin';
    public const TEACHER = 'teacher';
    public const STUDENT = 'student';
    public const PARENT = 'parent';

    public const ROLES = [self::SUPER_ADMIN, self::ADMIN, self::TEACHER, self::STUDENT, self::PARENT];

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /** Emails are case-insensitive identifiers; always store them lowercase. */
    protected function email(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::set(fn ($value) => $value === null ? null : strtolower(trim($value)));
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /** Students for whom this user is a parent/guardian. */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'guardian_student', 'parent_id', 'student_id')
            ->withPivot('relationship')->withTimestamps();
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ADMIN;
    }

    public function isStaff(): bool
    {
        return $this->hasRole(self::ADMIN, self::TEACHER);
    }

    /**
     * Student ids this user may see personal data for.
     * Returns null when the user can see every student in the institute.
     */
    public function visibleStudentIds(): ?array
    {
        return match ($this->role) {
            self::ADMIN => null,
            self::TEACHER => \DB::table('batch_student')
                ->whereIn('batch_id', $this->visibleBatchIds() ?? [])
                ->distinct()->pluck('student_id')->all(),
            self::STUDENT => $this->student ? [$this->student->id] : [],
            self::PARENT => $this->children()->pluck('students.id')->all(),
            default => [],
        };
    }

    /**
     * Batch ids this user may access. Null means every batch in the institute.
     */
    public function visibleBatchIds(): ?array
    {
        if (array_key_exists('visibleBatchIds', $this->runtimeCache)) {
            return $this->runtimeCache['visibleBatchIds'];
        }

        $ids = match ($this->role) {
            self::ADMIN => null,
            self::TEACHER => Batch::where('teacher_id', $this->teacher?->id ?? 0)->pluck('id')->all(),
            self::STUDENT, self::PARENT => \DB::table('batch_student')
                ->whereIn('student_id', $this->visibleStudentIds())
                ->where('status', 'active')
                ->distinct()->pluck('batch_id')->all(),
            default => [],
        };

        return $this->runtimeCache['visibleBatchIds'] = $ids;
    }

    public function canAccessBatch(?int $batchId): bool
    {
        if ($batchId === null) {
            return $this->isAdmin();
        }
        $ids = $this->visibleBatchIds();

        return $ids === null || in_array($batchId, $ids, true);
    }

    public function canAccessStudent(int $studentId): bool
    {
        $ids = $this->visibleStudentIds();

        return $ids === null || in_array($studentId, $ids, true);
    }

    /** @var array<string, mixed> per-request memoisation */
    protected array $runtimeCache = [];

    public function toAuthArray(): array
    {
        $this->loadMissing('institute', 'teacher', 'student');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'institute_id' => $this->institute_id,
            'institute' => $this->institute?->only(['id', 'name', 'slug', 'city', 'plan', 'currency']),
            'teacher_id' => $this->teacher?->id,
            'student_id' => $this->student?->id,
        ];
    }
}
