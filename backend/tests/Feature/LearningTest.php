<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LearningTest extends TestCase
{
    public function test_teacher_uploads_note_and_student_completes_it(): void
    {
        Storage::fake('local');
        [$inst] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $batch = $this->makeBatch($inst, $teacher);
        $student = $this->makeStudent($inst, [$batch]);

        $this->actingAsUser($teacher->user);
        $id = $this->post('/api/lessons', [
            'batch_id' => $batch->id, 'title' => 'Organic chemistry notes', 'type' => 'note',
            'file' => UploadedFile::fake()->create('notes.pdf', 200, 'application/pdf'), 'is_published' => '1',
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->postJson('/api/lessons', ['batch_id' => $batch->id, 'title' => 'Draft', 'type' => 'note', 'is_published' => false])->assertCreated();

        $lesson = Lesson::find($id);
        Storage::disk('local')->assertExists($lesson->file_path); // private storage

        $this->actingAsUser($student->user);
        $this->getJson('/api/lessons')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.completed', false);
        $this->postJson("/api/lessons/$id/complete")->assertOk();
        $this->getJson('/api/lessons')->assertJsonPath('data.0.completed', true);
        $this->postJson('/api/lessons', ['batch_id' => $batch->id, 'title' => 'X', 'type' => 'note'])->assertForbidden();
    }

    public function test_video_lessons_require_url(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $this->actingAsUser($admin)->postJson('/api/lessons', ['batch_id' => $batch->id, 'title' => 'V', 'type' => 'video'])
            ->assertStatus(422)->assertJsonValidationErrors('video_url');
    }

    public function test_doubt_flow_notifies_teacher_and_can_be_resolved(): void
    {
        [$inst] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $batch = $this->makeBatch($inst, $teacher);
        $student = $this->makeStudent($inst, [$batch]);

        $this->actingAsUser($student->user);
        $threadId = $this->postJson('/api/threads', ['batch_id' => $batch->id, 'type' => 'doubt', 'title' => 'Help with moles', 'body' => 'How to calculate?'])
            ->assertCreated()->json('id');
        $this->assertDatabaseHas('app_notifications', ['user_id' => $teacher->user_id, 'type' => 'doubt']);

        $this->actingAsUser($teacher->user);
        $replyId = $this->postJson("/api/threads/$threadId/replies", ['body' => 'Use n = m / M'])->assertCreated()->json('id');
        $this->assertDatabaseHas('app_notifications', ['user_id' => $student->user_id, 'type' => 'doubt']);
        $this->postJson("/api/replies/$replyId/accept")->assertOk();

        $this->getJson("/api/threads/$threadId")->assertOk()->assertJsonPath('status', 'resolved')->assertJsonPath('replies.0.is_answer', true);
    }

    public function test_parents_cannot_use_discussions(): void
    {
        [$inst] = $this->makeInstitute();
        $parent = $this->makeUser($inst, User::PARENT);
        $this->actingAsUser($parent)->getJson('/api/threads')->assertForbidden();
    }
}
