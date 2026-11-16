<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use Tests\TestCase;

class ExamTest extends TestCase
{
    private function setUpExam(array $examAttrs = []): array
    {
        [$inst] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $batch = $this->makeBatch($inst, $teacher);
        $student = $this->makeStudent($inst, [$batch]);

        $this->actingAsUser($teacher->user);
        $examId = $this->postJson('/api/exams', array_merge([
            'batch_id' => $batch->id, 'title' => 'Unit test', 'duration_minutes' => 30, 'pass_percentage' => 50,
        ], $examAttrs))->assertCreated()->json('id');

        // Publishing without questions is blocked
        $this->putJson("/api/exams/$examId", ['batch_id' => $batch->id, 'title' => 'Unit test', 'duration_minutes' => 30, 'is_published' => true])
            ->assertStatus(422);

        $q = [];
        $q['mcq'] = $this->postJson("/api/exams/$examId/questions", ['type' => 'mcq', 'question' => '2+2?', 'options' => ['3', '4', '5'], 'correct_answer' => [1], 'marks' => 2])->assertCreated()->json('id');
        $q['tf'] = $this->postJson("/api/exams/$examId/questions", ['type' => 'true_false', 'question' => 'Colombo is the commercial capital', 'correct_answer' => [true]])->json('id');
        $q['short'] = $this->postJson("/api/exams/$examId/questions", ['type' => 'short_answer', 'question' => 'Chemical symbol of sodium', 'correct_answer' => ['Na']])->json('id');
        $q['num'] = $this->postJson("/api/exams/$examId/questions", ['type' => 'numeric', 'question' => 'g (m/s²)?', 'correct_answer' => [9.8, 0.1], 'marks' => 2])->json('id');
        $q['multi'] = $this->postJson("/api/exams/$examId/questions", ['type' => 'multi_select', 'question' => 'Even numbers', 'options' => ['1', '2', '3', '4'], 'correct_answer' => [1, 3], 'marks' => 3])->json('id');

        $this->putJson("/api/exams/$examId", array_merge(['batch_id' => $batch->id, 'title' => 'Unit test', 'duration_minutes' => 30, 'is_published' => true], $examAttrs))
            ->assertOk();

        return [$examId, $q, $student, $teacher, $batch];
    }

    public function test_exam_language_defaults_to_batch_medium_and_sinhala_exam_works_end_to_end(): void
    {
        [$inst] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $batch = $this->makeBatch($inst, $teacher, ['medium' => 'Sinhala']);
        $student = $this->makeStudent($inst, [$batch]);

        $this->actingAsUser($teacher->user);
        $examId = $this->postJson('/api/exams', ['batch_id' => $batch->id, 'title' => 'විද්‍යාව - ඒකක පරීක්ෂණය', 'duration_minutes' => 20])
            ->assertCreated()->assertJsonPath('language', 'si')->json('id');
        $mcq = $this->postJson("/api/exams/$examId/questions", [
            'type' => 'mcq', 'question' => 'ජලයේ රසායනික සූත්‍රය කුමක්ද?', 'options' => ['H₂O', 'CO₂', 'O₂'], 'correct_answer' => [0],
        ])->assertCreated()->json('id');
        $short = $this->postJson("/api/exams/$examId/questions", [
            'type' => 'short_answer', 'question' => 'රුධිරය පොම්ප කරන ඉන්ද්‍රිය කුමක්ද?', 'correct_answer' => ['හදවත', 'හෘදය'],
        ])->assertCreated()->json('id');
        // Publishing without sending language keeps Sinhala
        $this->putJson("/api/exams/$examId", ['batch_id' => $batch->id, 'title' => 'විද්‍යාව - ඒකක පරීක්ෂණය', 'duration_minutes' => 20, 'is_published' => true])
            ->assertOk()->assertJsonPath('language', 'si');

        $this->actingAsUser($student->user);
        $start = $this->postJson("/api/exams/$examId/start")->assertOk()
            ->assertJsonPath('exam.language', 'si')
            ->assertJsonPath('questions.0.question', 'ජලයේ රසායනික සූත්‍රය කුමක්ද?');
        $this->postJson('/api/attempts/'.$start->json('attempt.id').'/submit', ['answers' => [$mcq => 0, $short => ' හෘදය ']])
            ->assertOk()->assertJsonPath('score', 2)->assertJsonPath('exam.language', 'si');
    }

    public function test_exam_language_can_be_overridden_and_is_validated(): void
    {
        [$inst] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $batch = $this->makeBatch($inst, $teacher, ['medium' => 'Sinhala']);
        $this->actingAsUser($teacher->user);
        $this->postJson('/api/exams', ['batch_id' => $batch->id, 'title' => 'T', 'duration_minutes' => 10, 'language' => 'ta'])
            ->assertCreated()->assertJsonPath('language', 'ta');
        $this->postJson('/api/exams', ['batch_id' => $batch->id, 'title' => 'T', 'duration_minutes' => 10, 'language' => 'fr'])
            ->assertStatus(422)->assertJsonValidationErrors('language');
    }

    public function test_question_validation(): void
    {
        [$examId] = $this->setUpExam();
        $this->postJson("/api/exams/$examId/questions", ['type' => 'mcq', 'question' => 'Q', 'options' => ['a', 'b'], 'correct_answer' => [5]])
            ->assertStatus(422)->assertJsonValidationErrors('correct_answer');
        $this->postJson("/api/exams/$examId/questions", ['type' => 'numeric', 'question' => 'Q', 'correct_answer' => ['abc']])
            ->assertStatus(422);
    }

    public function test_student_takes_exam_and_it_is_auto_marked(): void
    {
        [$examId, $q, $student] = $this->setUpExam();

        $this->actingAsUser($student->user);
        $start = $this->postJson("/api/exams/$examId/start")->assertOk();
        // Correct answers are never sent to the student
        $this->assertArrayNotHasKey('correct_answer', $start->json('questions.0'));
        $attemptId = $start->json('attempt.id');

        // Autosave, then submit (the submit payload wins)
        $this->putJson("/api/attempts/$attemptId/answers", ['answers' => [$q['mcq'] => 0]])->assertOk();

        $res = $this->postJson("/api/attempts/$attemptId/submit", ['answers' => [
            $q['mcq'] => 1,              // correct (2)
            $q['tf'] => true,            // correct (1)
            $q['short'] => '  na ',      // correct, case/space-insensitive (1)
            $q['num'] => 9.75,           // within tolerance (2)
            $q['multi'] => [1],          // incomplete -> wrong (0 of 3)
        ]])->assertOk();

        $res->assertJsonPath('status', 'submitted')->assertJsonPath('score', 6)->assertJsonPath('total_marks', 9)->assertJsonPath('passed', true);
        $this->assertEqualsWithDelta(66.67, $res->json('percentage'), 0.01);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $student->user_id, 'type' => 'exam']);

        // Max attempts (1) reached
        $this->postJson("/api/exams/$examId/start")->assertStatus(422);
        // Cannot resubmit
        $this->postJson("/api/attempts/$attemptId/submit", ['answers' => []])->assertStatus(422);
    }

    public function test_late_submission_keeps_only_autosaved_answers(): void
    {
        [$examId, $q, $student] = $this->setUpExam();
        $this->actingAsUser($student->user);
        $attemptId = $this->postJson("/api/exams/$examId/start")->json('attempt.id');
        $this->putJson("/api/attempts/$attemptId/answers", ['answers' => [$q['mcq'] => 1]])->assertOk();

        ExamAttempt::whereKey($attemptId)->update(['started_at' => now()->subHours(2)]);

        $this->postJson("/api/attempts/$attemptId/submit", ['answers' => [$q['tf'] => true, $q['num'] => 9.8]])
            ->assertOk()->assertJsonPath('score', 2);
    }

    public function test_unpublished_or_closed_exam_cannot_be_started(): void
    {
        [$examId, , $student] = $this->setUpExam(['ends_at' => now()->subMinute()->toDateTimeString(), 'starts_at' => now()->subDay()->toDateTimeString()]);
        $this->actingAsUser($student->user)->postJson("/api/exams/$examId/start")->assertStatus(422);
    }

    public function test_student_outside_batch_cannot_start(): void
    {
        [$examId] = $this->setUpExam();
        $exam = Exam::withoutGlobalScopes()->find($examId);
        $outsider = $this->makeStudent($exam->institute);
        $this->actingAsUser($outsider->user)->postJson("/api/exams/$examId/start")->assertForbidden();
    }

    public function test_teacher_sees_results_and_item_analysis(): void
    {
        [$examId, $q, $student, $teacher] = $this->setUpExam();
        $this->actingAsUser($student->user);
        $attemptId = $this->postJson("/api/exams/$examId/start")->json('attempt.id');
        $this->postJson("/api/attempts/$attemptId/submit", ['answers' => [$q['mcq'] => 1]])->assertOk();

        $this->actingAsUser($teacher->user)->getJson("/api/exams/$examId/results")->assertOk()
            ->assertJsonPath('stats.attempted', 1)
            ->assertJsonPath('stats.pass_rate', 0)
            ->assertJsonCount(5, 'questions');
    }
}
