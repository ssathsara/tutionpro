<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\User;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    public function test_teacher_marks_attendance_and_parents_are_alerted_of_absence(): void
    {
        [$inst] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $batch = $this->makeBatch($inst, $teacher);
        $parent = $this->makeUser($inst, User::PARENT, ['phone' => '0779998887']);
        $present = $this->makeStudent($inst, [$batch]);
        $absent = $this->makeStudent($inst, [$batch], $parent);
        $session = ClassSession::create(['institute_id' => $inst->id, 'batch_id' => $batch->id, 'date' => today(), 'start_time' => '08:00:00']);

        $this->actingAsUser($teacher->user)->postJson("/api/class-sessions/{$session->id}/attendance", [
            'records' => [
                ['student_id' => $present->id, 'status' => 'present'],
                ['student_id' => $absent->id, 'status' => 'absent'],
            ],
        ])->assertOk()->assertJsonPath('absent_alerts', 1);

        $this->assertDatabaseHas('attendances', ['student_id' => $absent->id, 'status' => 'absent']);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $parent->id, 'type' => 'attendance']);
        $this->assertDatabaseHas('sms_logs', ['to' => '94779998887']);
        $this->assertSame('completed', $session->fresh()->status);

        // Roster reflects saved statuses
        $this->getJson("/api/class-sessions/{$session->id}/attendance")->assertOk()
            ->assertJsonFragment(['student_id' => $absent->id, 'status' => 'absent']);
    }

    public function test_teacher_cannot_mark_attendance_for_another_batch(): void
    {
        [$inst] = $this->makeInstitute();
        $t1 = $this->makeTeacher($inst);
        $batch = $this->makeBatch($inst, $this->makeTeacher($inst));
        $session = ClassSession::create(['institute_id' => $inst->id, 'batch_id' => $batch->id, 'date' => today(), 'start_time' => '08:00:00']);

        $this->actingAsUser($t1->user)->postJson("/api/class-sessions/{$session->id}/attendance", [
            'records' => [['student_id' => 1, 'status' => 'present']],
        ])->assertForbidden();
    }

    public function test_student_joining_live_class_records_online_attendance(): void
    {
        [$inst] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $student = $this->makeStudent($inst, [$batch]);
        $session = ClassSession::create([
            'institute_id' => $inst->id, 'batch_id' => $batch->id, 'date' => today(), 'start_time' => now()->format('H:i:s'),
            'type' => 'live', 'meeting_url' => 'https://meet.jit.si/test-room',
        ]);

        $this->actingAsUser($student->user);
        // Link is not leaked in listings
        $this->getJson('/api/class-sessions?type=live')->assertOk()->assertJsonMissingPath('data.0.meeting_url');
        $this->postJson("/api/class-sessions/{$session->id}/join")->assertOk()->assertJsonPath('meeting_url', 'https://meet.jit.si/test-room');
        $this->assertDatabaseHas('attendances', ['student_id' => $student->id, 'class_session_id' => $session->id, 'method' => 'online', 'status' => 'present']);
    }

    public function test_attendance_summary(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $student = $this->makeStudent($inst, [$batch]);
        foreach (['present', 'present', 'absent', 'late'] as $i => $status) {
            $s = ClassSession::create(['institute_id' => $inst->id, 'batch_id' => $batch->id, 'date' => today()->subDays($i + 1), 'start_time' => '08:00:00']);
            $s->attendances()->create(['institute_id' => $inst->id, 'student_id' => $student->id, 'status' => $status]);
        }

        $this->actingAsUser($admin)->getJson("/api/students/{$student->id}/attendance")
            ->assertOk()->assertJsonPath('summary.total', 4)->assertJsonPath('summary.rate', 75);
    }

    public function test_sessions_are_generated_from_timetable(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $this->actingAsUser($admin)->postJson('/api/timetable', ['batch_id' => $batch->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '10:00'])
            ->assertCreated();

        $monday = today()->next('Monday');
        $this->postJson('/api/class-sessions/generate', ['from' => $monday->toDateString(), 'to' => $monday->copy()->addDays(13)->toDateString()])
            ->assertOk()->assertJsonPath('created', 2);
        // Idempotent
        $this->postJson('/api/class-sessions/generate', ['from' => $monday->toDateString(), 'to' => $monday->copy()->addDays(13)->toDateString()])
            ->assertJsonPath('created', 0);
    }
}
