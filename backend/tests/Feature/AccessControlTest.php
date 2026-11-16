<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    public function test_student_cannot_manage_people(): void
    {
        [$inst] = $this->makeInstitute();
        $student = $this->makeStudent($inst);

        $this->actingAsUser($student->user);
        $this->postJson('/api/students', ['name' => 'X', 'email' => 'x@x.lk'])->assertForbidden();
        $this->getJson('/api/students')->assertForbidden();
        $this->getJson('/api/teachers')->assertForbidden();
    }

    public function test_teacher_only_sees_own_batches_and_students(): void
    {
        [$inst] = $this->makeInstitute();
        $t1 = $this->makeTeacher($inst);
        $t2 = $this->makeTeacher($inst);
        $mine = $this->makeBatch($inst, $t1);
        $other = $this->makeBatch($inst, $t2);
        $this->makeStudent($inst, [$mine]);
        $this->makeStudent($inst, [$other]);

        $this->actingAsUser($t1->user);
        $this->getJson('/api/batches')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $mine->id);
        $this->getJson("/api/batches/{$other->id}")->assertForbidden();
        $this->getJson('/api/students')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_parent_can_view_own_child_only(): void
    {
        [$inst] = $this->makeInstitute();
        $parent = $this->makeUser($inst, User::PARENT);
        $child = $this->makeStudent($inst, [], $parent);
        $stranger = $this->makeStudent($inst);

        $this->actingAsUser($parent);
        $this->getJson("/api/students/{$child->id}")->assertOk()->assertJsonPath('id', $child->id);
        $this->getJson("/api/students/{$stranger->id}")->assertForbidden();
        $this->getJson('/api/parent/children')->assertOk()->assertJsonCount(1);
    }

    public function test_admin_can_create_student_with_parent_and_enrollment(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);

        $res = $this->actingAsUser($admin)->postJson('/api/students', [
            'name' => 'Sachini Perera',
            'email' => 'sachini@example.lk',
            'phone' => '0771112223',
            'grade' => 'A/L',
            'batch_ids' => [$batch->id],
            'parent_name' => 'Mr. Perera',
            'parent_email' => 'perera@example.lk',
        ])->assertCreated();

        $this->assertNotEmpty($res->json('admission_no'));
        $this->assertDatabaseHas('batch_student', ['batch_id' => $batch->id, 'student_id' => $res->json('id')]);
        $parent = User::where('email', 'perera@example.lk')->first();
        $this->assertSame(User::PARENT, $parent->role);
        $this->assertSame(1, $parent->children()->count());
    }

    public function test_batch_capacity_is_enforced(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst, null, ['capacity' => 1]);
        $s1 = $this->makeStudent($inst);
        $s2 = $this->makeStudent($inst);

        $this->actingAsUser($admin)->postJson("/api/batches/{$batch->id}/students", ['student_ids' => [$s1->id, $s2->id]])
            ->assertStatus(422);
    }
}
