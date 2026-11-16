<?php

namespace Tests;

use App\Models\Batch;
use App\Models\Institute;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // institute_id is guarded on tenant models; fixtures set it explicitly.
        \Illuminate\Database\Eloquent\Model::unguard();
    }

    protected function tearDown(): void
    {
        \Illuminate\Database\Eloquent\Model::reguard();
        parent::tearDown();
    }


    /** Creates an institute with an admin; returns [institute, admin]. */
    protected function makeInstitute(string $name = 'Test Institute'): array
    {
        $institute = Institute::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(4)]);
        $admin = $this->makeUser($institute, User::ADMIN);

        return [$institute, $admin];
    }

    protected function makeUser(Institute $institute, string $role, array $attrs = []): User
    {
        return User::withoutEvents(fn () => User::create(array_merge([
            'institute_id' => $institute->id,
            'name' => ucfirst($role).' '.Str::random(4),
            'email' => Str::random(10).'@example.lk',
            'phone' => '0771234567',
            'password' => 'password1',
            'role' => $role,
        ], $attrs)));
    }

    protected function makeTeacher(Institute $institute): Teacher
    {
        $u = $this->makeUser($institute, User::TEACHER);

        return Teacher::create(['institute_id' => $institute->id, 'user_id' => $u->id]);
    }

    protected function makeStudent(Institute $institute, array $batches = [], ?User $parent = null): Student
    {
        $u = $this->makeUser($institute, User::STUDENT);
        $s = Student::create(['institute_id' => $institute->id, 'user_id' => $u->id, 'admission_no' => 'ST'.Str::random(6)]);
        foreach ($batches as $b) {
            $s->batches()->attach($b->id, ['status' => 'active', 'enrolled_on' => now()->toDateString()]);
        }
        $parent?->children()->attach($s->id);

        return $s;
    }

    protected function makeBatch(Institute $institute, ?Teacher $teacher = null, array $attrs = []): Batch
    {
        $subject = Subject::create(['institute_id' => $institute->id, 'name' => 'Subject '.Str::random(5)]);

        return Batch::create(array_merge([
            'institute_id' => $institute->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher?->id,
            'name' => 'Batch '.Str::random(4),
            'medium' => 'Sinhala',
            'mode' => 'physical',
            'monthly_fee' => 2500,
        ], $attrs));
    }

    /** Authenticate as a user for subsequent requests (fresh tenant each time). */
    protected function actingAsUser(User $user): static
    {
        app(Tenant::class)->set(null);
        Sanctum::actingAs($user->fresh());

        return $this;
    }
}
