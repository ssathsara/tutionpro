<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Tests\TestCase;

class EngagementTest extends TestCase
{
    public function test_leaderboard_ranks_by_composite_score(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $top = $this->makeStudent($inst, [$batch]);
        $low = $this->makeStudent($inst, [$batch]);

        $exam = Exam::create(['institute_id' => $inst->id, 'batch_id' => $batch->id, 'title' => 'E', 'is_published' => true]);
        foreach ([[$top, 90], [$low, 40]] as [$s, $pct]) {
            ExamAttempt::create(['institute_id' => $inst->id, 'exam_id' => $exam->id, 'student_id' => $s->id, 'started_at' => now(),
                'submitted_at' => now(), 'status' => 'submitted', 'percentage' => $pct]);
        }
        $session = ClassSession::create(['institute_id' => $inst->id, 'batch_id' => $batch->id, 'date' => today(), 'start_time' => '08:00:00', 'status' => 'completed']);
        Attendance::create(['institute_id' => $inst->id, 'class_session_id' => $session->id, 'student_id' => $top->id, 'status' => 'present']);

        $res = $this->actingAsUser($top->user)->getJson("/api/leaderboard?batch_id={$batch->id}")->assertOk();
        $res->assertJsonPath('rows.0.student_id', $top->id)
            ->assertJsonPath('rows.0.rank', 1)
            ->assertJsonPath('rows.0.points', 90 * 6 + 100 * 3)
            ->assertJsonPath('rows.0.is_me', true)
            ->assertJsonPath('rows.1.rank', 2);
    }

    public function test_certificates_issued_to_passers_and_publicly_verifiable(): void
    {
        [$inst] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $batch = $this->makeBatch($inst, $teacher);
        $pass = $this->makeStudent($inst, [$batch]);
        $fail = $this->makeStudent($inst, [$batch]);
        $exam = Exam::create(['institute_id' => $inst->id, 'batch_id' => $batch->id, 'title' => 'Final', 'pass_percentage' => 50, 'is_published' => true]);
        foreach ([[$pass, 75], [$fail, 30]] as [$s, $pct]) {
            ExamAttempt::create(['institute_id' => $inst->id, 'exam_id' => $exam->id, 'student_id' => $s->id, 'started_at' => now(), 'status' => 'submitted', 'percentage' => $pct]);
        }

        $this->actingAsUser($teacher->user)->postJson("/api/exams/{$exam->id}/certificates", ['title' => 'Course completion', 'type' => 'completion'])
            ->assertOk()->assertJsonPath('issued', 1);

        $this->actingAsUser($pass->user);
        $serial = $this->getJson('/api/certificates')->assertOk()->assertJsonPath('total', 1)->json('data.0.serial');

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/certificates/verify/$serial")->assertOk()->assertJsonPath('valid', true)->assertJsonPath('student_name', $pass->user->name);
        $this->getJson('/api/certificates/verify/TP-0000-NOPE')->assertNotFound()->assertJsonPath('valid', false);
    }

    public function test_announcement_reaches_target_audience_only(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $parent = $this->makeUser($inst, User::PARENT);
        $student = $this->makeStudent($inst, [$batch], $parent);
        $teacher = $this->makeTeacher($inst);

        $this->actingAsUser($admin)->postJson('/api/announcements', ['title' => 'Fee reminder', 'body' => 'Pay by 10th', 'audience' => 'parents', 'send_sms' => true])
            ->assertCreated()->assertJsonPath('recipients', 1);

        $this->assertDatabaseHas('app_notifications', ['user_id' => $parent->id, 'type' => 'announcement']);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $student->user_id]);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $teacher->user_id]);
        $this->assertDatabaseCount('sms_logs', 1);

        $this->actingAsUser($student->user)->getJson('/api/announcements')->assertOk()->assertJsonPath('total', 0);
        $this->actingAsUser($parent)->getJson('/api/announcements')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_teacher_announcements_are_limited_to_own_batches(): void
    {
        [$inst] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $other = $this->makeBatch($inst, $this->makeTeacher($inst));

        $this->actingAsUser($teacher->user);
        $this->postJson('/api/announcements', ['title' => 'T', 'body' => 'B', 'audience' => 'all'])->assertStatus(422);
        $this->postJson('/api/announcements', ['title' => 'T', 'body' => 'B', 'audience' => 'all', 'batch_id' => $other->id])->assertForbidden();
    }

    public function test_notifications_can_be_read(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $student = $this->makeStudent($inst, [$batch]);
        $this->actingAsUser($admin)->postJson('/api/announcements', ['title' => 'Hello', 'body' => 'World', 'audience' => 'students'])->assertCreated();

        $this->actingAsUser($student->user);
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('count', 1);
        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('count', 0);
    }

    public function test_dashboards_and_analytics_load_for_every_role(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $batch = $this->makeBatch($inst, $teacher);
        $parent = $this->makeUser($inst, User::PARENT);
        $student = $this->makeStudent($inst, [$batch], $parent);
        $super = User::create(['name' => 'Root', 'email' => 'root@tp.lk', 'password' => 'password1', 'role' => User::SUPER_ADMIN]);

        foreach ([$admin, $teacher->user, $student->user, $parent, $super] as $u) {
            $this->actingAsUser($u)->getJson('/api/dashboard')->assertOk()->assertJsonPath('role', $u->role);
        }
        $this->actingAsUser($admin);
        foreach (['overview', 'revenue', 'attendance', 'exams', 'at-risk'] as $ep) {
            $this->getJson("/api/analytics/$ep")->assertOk();
        }
        $this->actingAsUser($teacher->user)->getJson('/api/analytics/revenue')->assertForbidden();
        $this->actingAsUser($student->user)->getJson('/api/analytics/overview')->assertForbidden();
    }
}
