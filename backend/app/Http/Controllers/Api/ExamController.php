<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttemptAnswer;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Student;
use App\Models\User;
use App\Services\ExamGrader;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExamController extends Controller
{
    /** Grace period after the deadline for network latency. */
    private const GRACE_SECONDS = 60;

    public function __construct(private ExamGrader $grader, private Notifier $notifier) {}

    public function index(Request $request)
    {
        $user = $this->user();
        $ids = $user->visibleBatchIds();

        $q = Exam::query()->with('batch:id,name')
            ->withCount('questions')->withSum('questions as total_marks', 'marks')
            ->when($ids !== null, fn ($q) => $q->whereIn('batch_id', $ids))
            ->when(! $user->isStaff(), fn ($q) => $q->where('is_published', true))
            ->when($request->batch_id, fn ($q, $b) => $q->where('batch_id', $b))
            ->orderByDesc('id');

        $studentIds = $user->isStaff() ? null : $user->visibleStudentIds();

        return $q->paginate($this->perPage($request))->through(function (Exam $e) use ($studentIds) {
            $row = array_merge($e->toArray(), ['batch_name' => $e->batch?->name, 'is_open' => $e->isOpen()]);
            if ($studentIds !== null) {
                $attempts = $e->attempts()->whereIn('student_id', $studentIds)->orderByDesc('id')
                    ->get(['id', 'student_id', 'status', 'score', 'total_marks', 'percentage', 'submitted_at']);
                $row['my_attempts'] = $e->show_results ? $attempts : $attempts->map->only(['id', 'student_id', 'status', 'submitted_at']);
                $row['attempts_left'] = max(0, $e->max_attempts - $attempts->count());
            } else {
                $row['attempts_count'] = $e->attempts()->where('status', 'submitted')->count();
            }

            return $row;
        });
    }

    public function store(Request $request)
    {
        $data = $this->validateExam($request);
        $this->ensureBatch((int) $data['batch_id']);
        // Default the exam language to the batch's teaching medium.
        $data['language'] ??= Exam::languageForMedium(\App\Models\Batch::find($data['batch_id'])?->medium);
        $exam = Exam::create(array_merge($data, ['created_by' => $this->user()->id]));

        return response()->json($exam, 201);
    }

    public function show(Exam $exam)
    {
        $this->ensureBatch($exam->batch_id);
        $user = $this->user();
        $exam->load('batch:id,name');

        if ($user->isStaff()) {
            return array_merge($exam->toArray(), ['questions' => $exam->questions, 'is_open' => $exam->isOpen()]);
        }
        abort_unless($exam->is_published, 404);

        return array_merge($exam->toArray(), [
            'is_open' => $exam->isOpen(),
            'questions_count' => $exam->questions()->count(),
            'total_marks' => (float) $exam->questions()->sum('marks'),
        ]);
    }

    public function update(Request $request, Exam $exam)
    {
        $this->ensureBatch($exam->batch_id);
        $data = $this->validateExam($request);
        $this->ensureBatch((int) $data['batch_id']);
        $wasPublished = $exam->is_published;
        if (! empty($data['is_published']) && ! $wasPublished && ! $exam->questions()->exists()) {
            abort(422, 'Add at least one question before publishing.');
        }
        $exam->update($data);

        if (! $wasPublished && $exam->is_published) {
            $students = $exam->batch->activeStudents()->with('user', 'guardians')->get();
            $this->notifier->notify($students->pluck('user'), 'exam', 'New exam: '.$exam->title,
                $exam->starts_at ? 'Opens '.$exam->starts_at->format('d M Y H:i') : 'Available now', '/exams');
        }

        return $exam;
    }

    public function destroy(Exam $exam)
    {
        $this->ensureBatch($exam->batch_id);
        $exam->delete();

        return response()->noContent();
    }

    public function storeQuestion(Request $request, Exam $exam)
    {
        $this->ensureBatch($exam->batch_id);
        $data = $this->validateQuestion($request);
        $data['position'] ??= (int) $exam->questions()->max('position') + 1;

        return response()->json($exam->questions()->create($data), 201);
    }

    public function updateQuestion(Request $request, Exam $exam, ExamQuestion $question)
    {
        $this->ensureBatch($exam->batch_id);
        abort_unless($question->exam_id === $exam->id, 404);
        $question->update($this->validateQuestion($request));

        return $question;
    }

    public function destroyQuestion(Exam $exam, ExamQuestion $question)
    {
        $this->ensureBatch($exam->batch_id);
        abort_unless($question->exam_id === $exam->id, 404);
        $question->delete();

        return response()->noContent();
    }

    /** Student starts (or resumes) an attempt. */
    public function start(Exam $exam)
    {
        $user = $this->user();
        abort_unless($user->role === User::STUDENT && $user->student, 403);
        $this->ensureBatch($exam->batch_id);
        $student = $user->student;

        // Lock this student's row so simultaneous "Start" requests (double-click, two tabs, flaky
        // mobile retry) are handled one after another: the second resumes the first attempt
        // instead of creating another one and using up an attempt.
        $active = DB::transaction(function () use ($exam, $student) {
            \App\Models\Student::whereKey($student->id)->lockForUpdate()->first();

            $active = ExamAttempt::where(['exam_id' => $exam->id, 'student_id' => $student->id, 'status' => 'in_progress'])->first();
            if ($active && now()->gt($active->deadline()->addSeconds(self::GRACE_SECONDS))) {
                $this->finalize($active);
                $active = null;
            }

            if (! $active) {
                abort_unless($exam->isOpen(), 422, 'This exam is not open right now.');
                $used = ExamAttempt::where(['exam_id' => $exam->id, 'student_id' => $student->id])->count();
                abort_if($used >= $exam->max_attempts, 422, 'You have used all attempts for this exam.');
                $order = $exam->questions()->pluck('id')->all();
                abort_if(! $order, 422, 'This exam has no questions.');
                if ($exam->shuffle_questions) {
                    shuffle($order);
                }
                $active = ExamAttempt::create([
                    'exam_id' => $exam->id,
                    'student_id' => $student->id,
                    'started_at' => now(),
                    'question_order' => $order,
                    'status' => 'in_progress',
                ]);
            }

            return $active;
        });

        $questions = $exam->questions()->get()->keyBy('id');
        $saved = $active->answers()->pluck('answer', 'exam_question_id');

        return [
            'attempt' => [
                'id' => $active->id,
                'started_at' => $active->started_at,
                'deadline' => $active->deadline()->toIso8601String(),
                'server_time' => now()->toIso8601String(),
            ],
            'exam' => $exam->only(['id', 'title', 'language', 'instructions', 'duration_minutes']),
            'questions' => collect($active->question_order)->map(fn ($id) => $questions[$id] ?? null)->filter()
                ->map(fn (ExamQuestion $q) => $q->forStudent())->values(),
            'answers' => $saved,
        ];
    }

    /** Autosave answers during an attempt. */
    public function saveAnswers(Request $request, ExamAttempt $attempt)
    {
        $this->ensureOwnActiveAttempt($attempt);
        $data = $request->validate(['answers' => ['present', 'array']]);
        abort_if(now()->gt($attempt->deadline()->addSeconds(self::GRACE_SECONDS)), 422, 'Time is up.');
        $this->storeAnswers($attempt, $data['answers']);

        return response()->json(['message' => 'Saved']);
    }

    public function submit(Request $request, ExamAttempt $attempt)
    {
        $this->ensureOwnActiveAttempt($attempt);
        $data = $request->validate(['answers' => ['nullable', 'array']]);
        // Late submissions keep only what was autosaved before the deadline.
        if (! now()->gt($attempt->deadline()->addSeconds(self::GRACE_SECONDS))) {
            $this->storeAnswers($attempt, $data['answers'] ?? []);
        }
        $this->finalize($attempt);

        return $this->showAttempt($attempt->fresh());
    }

    public function showAttempt(ExamAttempt $attempt)
    {
        $user = $this->user();
        $this->ensureStudent($attempt->student_id);
        $attempt->load(['exam', 'student.user:id,name', 'answers']);
        $exam = $attempt->exam;
        $this->ensureBatch($exam->batch_id);

        $reveal = $user->isStaff() || $exam->show_results;
        $answers = $attempt->answers->keyBy('exam_question_id');
        $questions = $exam->questions()->get()->keyBy('id');
        $order = $attempt->question_order ?: $questions->keys()->all();

        return [
            'id' => $attempt->id,
            'status' => $attempt->status,
            'student_name' => $attempt->student?->user?->name,
            'started_at' => $attempt->started_at,
            'submitted_at' => $attempt->submitted_at,
            'exam' => $exam->only(['id', 'title', 'language', 'pass_percentage', 'show_results']),
            'score' => $reveal ? $attempt->score : null,
            'total_marks' => $attempt->total_marks,
            'percentage' => $reveal ? $attempt->percentage : null,
            'passed' => $reveal && $attempt->percentage !== null ? $attempt->percentage >= $exam->pass_percentage : null,
            'questions' => $reveal ? collect($order)->map(fn ($id) => $questions[$id] ?? null)->filter()->map(fn (ExamQuestion $q) => array_merge($q->forStudent(), [
                'your_answer' => $answers[$q->id]->answer ?? null,
                'is_correct' => (bool) ($answers[$q->id]->is_correct ?? false),
                'marks_awarded' => (float) ($answers[$q->id]->marks_awarded ?? 0),
                'correct_answer' => $q->correct_answer,
                'explanation' => $q->explanation,
            ]))->values() : [],
        ];
    }

    /** Teacher view of results and item analysis. */
    public function results(Exam $exam)
    {
        $this->ensureBatch($exam->batch_id);
        $attempts = $exam->attempts()->where('status', 'submitted')->with('student.user:id,name')
            ->orderByDesc('percentage')->get();
        $questions = $exam->questions()->get();
        $correctRates = AttemptAnswer::query()
            ->whereIn('exam_attempt_id', $attempts->pluck('id'))
            ->selectRaw('exam_question_id, avg(case when is_correct then 1 else 0 end) * 100 as rate')
            ->groupBy('exam_question_id')->pluck('rate', 'exam_question_id');

        $enrolled = $exam->batch->activeStudents()->count();
        $pct = $attempts->pluck('percentage');

        return [
            'exam' => $exam->only(['id', 'title', 'pass_percentage', 'batch_id']),
            'stats' => [
                'enrolled' => $enrolled,
                'attempted' => $attempts->unique('student_id')->count(),
                'average' => $pct->count() ? round($pct->avg(), 1) : null,
                'highest' => $pct->max(),
                'lowest' => $pct->min(),
                'pass_rate' => $pct->count() ? round($pct->filter(fn ($p) => $p >= $exam->pass_percentage)->count() / $pct->count() * 100, 1) : null,
            ],
            'attempts' => $attempts->map(fn (ExamAttempt $a) => [
                'id' => $a->id,
                'student_id' => $a->student_id,
                'student_name' => $a->student?->user?->name,
                'admission_no' => $a->student?->admission_no,
                'score' => $a->score,
                'total_marks' => $a->total_marks,
                'percentage' => $a->percentage,
                'passed' => $a->percentage >= $exam->pass_percentage,
                'submitted_at' => $a->submitted_at,
                'duration_minutes' => $a->submitted_at ? round($a->started_at->diffInSeconds($a->submitted_at) / 60, 1) : null,
            ]),
            'questions' => $questions->map(fn (ExamQuestion $q) => [
                'id' => $q->id,
                'question' => $q->question,
                'type' => $q->type,
                'marks' => $q->marks,
                'correct_rate' => isset($correctRates[$q->id]) ? round((float) $correctRates[$q->id], 1) : null,
            ]),
        ];
    }

    private function ensureOwnActiveAttempt(ExamAttempt $attempt): void
    {
        $user = $this->user();
        abort_unless($user->role === User::STUDENT && $user->student?->id === $attempt->student_id, 403);
        abort_unless($attempt->status === 'in_progress', 422, 'This attempt has already been submitted.');
    }

    private function storeAnswers(ExamAttempt $attempt, array $answers): void
    {
        $valid = array_flip($attempt->question_order ?? []);
        foreach ($answers as $qid => $answer) {
            if (! isset($valid[(int) $qid])) {
                continue;
            }
            AttemptAnswer::updateOrCreate(
                ['exam_attempt_id' => $attempt->id, 'exam_question_id' => (int) $qid],
                ['answer' => $answer === null ? null : (is_array($answer) ? $answer : [$answer])],
            );
        }
    }

    /** Auto-mark every question and close the attempt. */
    private function finalize(ExamAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt) {
            $attempt->load('exam');
            // Only mark the questions this student was actually given. Questions a teacher adds
            // after the attempt started were never shown, so they must not count against the student.
            $questions = $attempt->exam->questions()
                ->when($attempt->question_order, fn ($q) => $q->whereIn('id', $attempt->question_order))
                ->get();
            $answers = $attempt->answers()->get()->keyBy('exam_question_id');
            $score = 0;
            $total = 0;

            foreach ($questions as $q) {
                $total += $q->marks;
                $row = $answers[$q->id] ?? AttemptAnswer::make(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $q->id, 'answer' => null]);
                $value = $row->answer;
                $ok = $this->grader->isCorrect($q, is_array($value) && $q->type !== 'multi_select' ? ($value[0] ?? null) : $value);
                $row->is_correct = $ok;
                $row->marks_awarded = $ok ? $q->marks : 0;
                $row->save();
                $score += $row->marks_awarded;
            }

            $attempt->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'score' => $score,
                'total_marks' => $total,
                'percentage' => $total > 0 ? round($score / $total * 100, 2) : 0,
            ]);
        });

        $attempt->refresh();
        if ($attempt->exam->show_results) {
            $student = Student::with('user', 'guardians')->find($attempt->student_id);
            $this->notifier->notify($student->notifiableUsers(), 'exam', 'Exam result: '.$attempt->exam->title,
                sprintf('%s scored %s/%s (%s%%)', $student->user?->name, $attempt->score + 0, $attempt->total_marks + 0, $attempt->percentage + 0),
                '/attempts/'.$attempt->id);
        }
    }

    private function validateExam(Request $request): array
    {
        $data = $this->validateExamFields($request);
        if (array_key_exists('language', $data) && ! $data['language']) {
            unset($data['language']); // keep the existing / default language
        }

        return $data;
    }

    private function validateExamFields(Request $request): array
    {
        return $request->validate([
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')->where('institute_id', $this->user()->institute_id)],
            'title' => ['required', 'string', 'max:200'],
            'language' => ['nullable', Rule::in(Exam::LANGUAGES)],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'duration_minutes' => ['required', 'integer', 'between:1,600'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'pass_percentage' => ['nullable', 'integer', 'between:0,100'],
            'max_attempts' => ['nullable', 'integer', 'between:1,20'],
            'shuffle_questions' => ['sometimes', 'boolean'],
            'show_results' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
        ]);
    }

    private function validateQuestion(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(ExamQuestion::TYPES)],
            'question' => ['required', 'string', 'max:5000'],
            'options' => ['nullable', 'array', 'max:10'],
            'options.*' => ['required', 'string', 'max:1000'],
            'correct_answer' => ['required', 'array', 'min:1'],
            'marks' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $opts = $data['options'] ?? [];
        $ans = $data['correct_answer'];
        $fail = fn ($m) => throw ValidationException::withMessages(['correct_answer' => $m]);

        switch ($data['type']) {
            case 'mcq':
                if (count($opts) < 2) {
                    $fail('Multiple choice questions need at least two options.');
                }
                if (! is_numeric($ans[0]) || (int) $ans[0] < 0 || (int) $ans[0] >= count($opts)) {
                    $fail('Select the correct option.');
                }
                $data['correct_answer'] = [(int) $ans[0]];
                break;
            case 'multi_select':
                if (count($opts) < 2) {
                    $fail('Multiple select questions need at least two options.');
                }
                foreach ($ans as $a) {
                    if (! is_numeric($a) || (int) $a < 0 || (int) $a >= count($opts)) {
                        $fail('Correct answers must reference existing options.');
                    }
                }
                $data['correct_answer'] = array_values(array_unique(array_map('intval', $ans)));
                break;
            case 'true_false':
                $data['options'] = ['True', 'False'];
                $v = filter_var($ans[0], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($v === null) {
                    $fail('Choose true or false.');
                }
                $data['correct_answer'] = [$v];
                break;
            case 'short_answer':
                $data['options'] = null;
                $data['correct_answer'] = array_values(array_filter(array_map(fn ($a) => trim((string) $a), $ans), 'strlen'));
                if (! $data['correct_answer']) {
                    $fail('Provide at least one accepted answer.');
                }
                break;
            case 'numeric':
                $data['options'] = null;
                if (! is_numeric($ans[0])) {
                    $fail('The correct answer must be a number.');
                }
                $data['correct_answer'] = [(float) $ans[0], isset($ans[1]) && is_numeric($ans[1]) ? abs((float) $ans[1]) : 0];
                break;
        }
        $data['marks'] ??= 1;

        return $data;
    }
}
