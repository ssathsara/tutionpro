<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\ClassSession;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Regression tests for problems found by walking through the system as each role.
 */
class RoleWalkthroughRegressionTest extends TestCase
{
    public function test_admin_created_student_without_email_or_password_gets_usable_credentials(): void
    {
        [$inst, $admin] = $this->makeInstitute('Pragathi');
        $res = $this->actingAsUser($admin)->postJson('/api/students', [
            'name' => 'Nimesha Perera', 'phone' => '0771112223', 'grade' => 'Grade 7',
            'parent_name' => 'Mr. Perera', 'parent_email' => 'perera.parent@example.lk',
        ])->assertCreated();

        $creds = collect($res->json('credentials'))->keyBy('role');
        $this->assertStringStartsWith(strtolower($res->json('admission_no')).'@', $creds['student']['login']);
        $this->assertSame('perera.parent@example.lk', $creds['parent']['login']);

        // Both can actually sign in with what the admin was shown.
        $this->app['auth']->forgetGuards();
        foreach ($creds as $c) {
            $this->postJson('/api/auth/login', ['email' => $c['login'], 'password' => $c['password']])->assertOk();
        }

        // Editing the student keeps working with the generated username in the form.
        $this->actingAsUser($admin)->putJson('/api/students/'.$res->json('id'), [
            'name' => 'Nimesha Perera', 'email' => $creds['student']['login'], 'grade' => 'Grade 8',
        ])->assertOk()->assertJsonPath('grade', 'Grade 8');
    }

    public function test_teacher_and_parent_creation_return_generated_password_once(): void
    {
        [, $admin] = $this->makeInstitute();
        $this->actingAsUser($admin);
        $t = $this->postJson('/api/teachers', ['name' => 'Kasun', 'email' => 'kasun@example.lk'])->assertCreated();
        $this->assertNotEmpty($t->json('credentials.0.password'));
        $p = $this->postJson('/api/parents', ['name' => 'Mrs. Silva', 'email' => 'silva@example.lk', 'password' => 'chosenpass1'])->assertCreated();
        $this->assertSame([], $p->json('credentials'), 'admin-chosen passwords are not echoed back');
    }

    public function test_institute_landline_numbers_are_accepted(): void
    {
        [$inst] = $this->makeInstitute();
        $super = User::create(['name' => 'Root', 'email' => 'root@tp.lk', 'password' => 'password1', 'role' => User::SUPER_ADMIN]);
        $this->actingAsUser($super)->putJson("/api/institutes/{$inst->id}", ['phone' => '0112345678'])->assertOk();
        $this->putJson("/api/institutes/{$inst->id}", ['phone' => '12345'])->assertStatus(422);
    }

    public function test_student_with_payments_cannot_be_deleted(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $student = $this->makeStudent($inst);
        $this->actingAsUser($admin);
        $inv = $this->postJson('/api/invoices', ['student_id' => $student->id, 'amount' => 2000])->json('id');
        $this->postJson("/api/invoices/$inv/payments", ['amount' => 2000, 'method' => 'cash'])->assertCreated();

        $this->deleteJson("/api/students/{$student->id}")->assertStatus(422);
        $this->assertDatabaseCount('payments', 1);

        // A student with no payment history can still be removed.
        $fresh = $this->makeStudent($inst);
        $this->deleteJson("/api/students/{$fresh->id}")->assertNoContent();
    }

    public function test_teacher_with_discussion_history_cannot_be_deleted(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $batch = $this->makeBatch($inst, $teacher);
        $this->actingAsUser($teacher->user)->postJson('/api/threads', ['batch_id' => $batch->id, 'type' => 'discussion', 'title' => 'T', 'body' => 'B'])->assertCreated();
        $this->actingAsUser($admin)->deleteJson("/api/teachers/{$teacher->id}")->assertStatus(422);
        $this->assertDatabaseCount('threads', 1);
    }

    public function test_tiny_balance_cannot_be_overpaid(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $student = $this->makeStudent($inst);
        $this->actingAsUser($admin);
        $inv = $this->postJson('/api/invoices', ['student_id' => $student->id, 'amount' => 1000, 'discount' => 999.5])->json('id');
        $this->postJson("/api/invoices/$inv/payments", ['amount' => 1, 'method' => 'cash'])->assertStatus(422);
        $this->postJson("/api/invoices/$inv/payments", ['amount' => 0.5, 'method' => 'cash'])->assertCreated();
        $this->postJson("/api/invoices/$inv/payments", ['amount' => 0.5, 'method' => 'cash'])->assertStatus(422);
    }

    public function test_question_added_mid_attempt_is_not_marked(): void
    {
        [$inst] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $batch = $this->makeBatch($inst, $teacher);
        $student = $this->makeStudent($inst, [$batch]);

        $this->actingAsUser($teacher->user);
        $exam = $this->postJson('/api/exams', ['batch_id' => $batch->id, 'title' => 'E', 'duration_minutes' => 30])->json('id');
        $q1 = $this->postJson("/api/exams/$exam/questions", ['type' => 'mcq', 'question' => '1+1', 'options' => ['1', '2'], 'correct_answer' => [1]])->json('id');
        $this->putJson("/api/exams/$exam", ['batch_id' => $batch->id, 'title' => 'E', 'duration_minutes' => 30, 'is_published' => true])->assertOk();

        $attempt = $this->actingAsUser($student->user)->postJson("/api/exams/$exam/start")->json('attempt.id');
        $this->actingAsUser($teacher->user)->postJson("/api/exams/$exam/questions", ['type' => 'mcq', 'question' => '2+2', 'options' => ['3', '4'], 'correct_answer' => [1]]);

        $this->actingAsUser($student->user)->postJson("/api/attempts/$attempt/submit", ['answers' => [$q1 => 1]])
            ->assertOk()->assertJsonPath('total_marks', 1)->assertJsonPath('percentage', 100);
    }

    public function test_live_class_can_only_be_joined_around_its_time(): void
    {
        [$inst] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $student = $this->makeStudent($inst, [$batch]);
        $make = fn ($date, $start, $end = null) => ClassSession::create([
            'institute_id' => $inst->id, 'batch_id' => $batch->id, 'date' => $date, 'start_time' => $start, 'end_time' => $end,
            'type' => 'live', 'meeting_url' => 'https://meet.jit.si/x',
        ]);
        $future = $make(today()->addDays(10), '19:00:00');
        $past = $make(today()->subDays(2), '08:00:00', '10:00:00');
        $soon = $make(today(), now()->addMinutes(10)->format('H:i:s'));

        $this->actingAsUser($student->user);
        $this->postJson("/api/class-sessions/{$future->id}/join")->assertStatus(422);
        $this->postJson("/api/class-sessions/{$past->id}/join")->assertStatus(422);
        $this->postJson("/api/class-sessions/{$soon->id}/join")->assertOk();
        $this->assertDatabaseMissing('attendances', ['class_session_id' => $future->id]);
        $this->assertDatabaseHas('attendances', ['class_session_id' => $soon->id, 'status' => 'present']);
    }

    public function test_hidden_lesson_cannot_be_completed(): void
    {
        [$inst] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $student = $this->makeStudent($inst, [$batch]);
        $draft = Lesson::create(['institute_id' => $inst->id, 'batch_id' => $batch->id, 'title' => 'Draft', 'type' => 'note', 'is_published' => false]);
        $this->actingAsUser($student->user)->postJson("/api/lessons/{$draft->id}/complete")->assertNotFound();
        $this->assertDatabaseCount('lesson_progress', 0);
    }

    public function test_scheduled_announcement_is_sent_at_its_time_not_when_written(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $student = $this->makeStudent($inst, [$batch]);

        $this->actingAsUser($admin)->postJson('/api/announcements', [
            'title' => 'Exam timetable', 'body' => 'Out tomorrow', 'audience' => 'students', 'send_sms' => true,
            'published_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertCreated()->assertJsonPath('recipients', 0)->assertJsonPath('scheduled', true);

        $this->assertDatabaseCount('app_notifications', 0);
        $this->assertDatabaseCount('sms_logs', 0);
        $this->actingAsUser($student->user)->getJson('/api/dashboard')->assertJsonCount(0, 'announcements');

        // Time passes; the scheduler sends it exactly once.
        $this->travel(25)->hours();
        Artisan::call('announcements:send-scheduled');
        Artisan::call('announcements:send-scheduled');
        $this->assertDatabaseHas('app_notifications', ['user_id' => $student->user_id, 'type' => 'announcement']);
        $this->assertDatabaseCount('app_notifications', 1);
        $this->assertDatabaseCount('sms_logs', 1);
        $this->assertNotNull(Announcement::withoutGlobalScopes()->first()->notified_at);
    }

    public function test_lesson_attachments_are_private_and_only_served_to_the_batch(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('public');
        [$inst] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $batch = $this->makeBatch($inst, $teacher);
        $student = $this->makeStudent($inst, [$batch]);
        $outsider = $this->makeStudent($inst, [$this->makeBatch($inst)]);

        $this->actingAsUser($teacher->user);
        $res = $this->post('/api/lessons', [
            'batch_id' => $batch->id, 'title' => 'Paid notes', 'type' => 'note', 'is_published' => '1',
            'file' => \Illuminate\Http\UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $lesson = Lesson::find($res->json('id'));

        // Stored privately; no public URL is exposed.
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($lesson->file_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($lesson->file_path);
        $this->assertArrayNotHasKey('file_url', $res->json());
        $this->assertTrue($res->json('has_file'));

        $this->actingAsUser($student->user)->get("/api/lessons/{$lesson->id}/file")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAsUser($outsider->user)->getJson("/api/lessons/{$lesson->id}/file")->assertForbidden();
        $this->app['auth']->forgetGuards();
        app(\App\Support\Tenant::class)->set(null);
        $this->getJson("/api/lessons/{$lesson->id}/file")->assertUnauthorized();
    }

    public function test_legacy_public_attachment_is_moved_to_private_storage_on_first_download(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('public');
        [$inst] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $student = $this->makeStudent($inst, [$batch]);
        \Illuminate\Support\Facades\Storage::disk('public')->put('lessons/old.pdf', 'PDF');
        $lesson = Lesson::create(['institute_id' => $inst->id, 'batch_id' => $batch->id, 'title' => 'Old', 'type' => 'note',
            'file_path' => 'lessons/old.pdf', 'file_name' => 'old.pdf']);

        $this->actingAsUser($student->user)->get("/api/lessons/{$lesson->id}/file")->assertOk();
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('lessons/old.pdf');
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing('lessons/old.pdf');
    }

    public function test_teachers_do_not_see_student_fees_but_admins_and_parents_do(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $batch = $this->makeBatch($inst, $teacher);
        $parent = $this->makeUser($inst, User::PARENT);
        $student = $this->makeStudent($inst, [$batch], $parent);
        $this->actingAsUser($admin)->postJson('/api/invoices', ['student_id' => $student->id, 'amount' => 2500])->assertCreated();

        $this->actingAsUser($teacher->user)->getJson("/api/students/{$student->id}")->assertOk()
            ->assertJsonMissingPath('invoices')->assertJsonMissingPath('outstanding')->assertJsonPath('can_see_fees', false);
        $this->actingAsUser($admin)->getJson("/api/students/{$student->id}")->assertJsonPath('outstanding', 2500)->assertJsonCount(1, 'invoices');
        $this->actingAsUser($parent)->getJson("/api/students/{$student->id}")->assertJsonPath('outstanding', 2500);
    }

    public function test_batch_with_history_cannot_be_deleted_but_an_empty_one_can(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $used = $this->makeBatch($inst);
        $student = $this->makeStudent($inst, [$used]);
        $session = ClassSession::create(['institute_id' => $inst->id, 'batch_id' => $used->id, 'date' => today(), 'start_time' => '08:00:00']);
        $session->attendances()->create(['institute_id' => $inst->id, 'student_id' => $student->id, 'status' => 'present']);
        $empty = $this->makeBatch($inst);

        $this->actingAsUser($admin);
        $this->deleteJson("/api/batches/{$used->id}")->assertStatus(422)->assertJsonFragment(['message' => 'This batch has attendance records, so deleting it would erase that history. Edit the batch and untick "Active" to close it instead.']);
        $this->assertDatabaseCount('attendances', 1);
        $this->deleteJson("/api/batches/{$empty->id}")->assertNoContent();
    }

    public function test_api_tokens_expire(): void
    {
        $this->assertNotNull(config('sanctum.expiration'));
        [, $admin] = $this->makeInstitute();
        $token = $admin->createToken('web')->plainTextToken;
        $this->travel(config('sanctum.expiration') + 1)->minutes();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
