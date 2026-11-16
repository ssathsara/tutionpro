<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Student;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BatchController extends Controller
{
    public function index(Request $request)
    {
        $q = Batch::visibleTo($this->user())
            ->with(['subject:id,name', 'teacher.user:id,name'])
            ->withCount(['activeStudents as students_count'])
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%$s%"))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name');

        $map = fn (Batch $b) => array_merge($b->toArray(), [
            'subject_name' => $b->subject?->name,
            'teacher_name' => $b->teacher?->user?->name,
        ]);

        if ($request->boolean('all')) {
            return $q->get()->map($map);
        }

        return $q->paginate($this->perPage($request))->through($map);
    }

    public function store(Request $request)
    {
        $batch = Batch::create($this->validateData($request));
        AuditLogger::log('batch.created', $batch);

        return response()->json($batch, 201);
    }

    public function show(Batch $batch)
    {
        $this->ensureBatch($batch->id);
        $batch->load(['subject', 'teacher.user:id,name,email,phone', 'slots']);

        $students = $batch->students()->with('user:id,name,email,phone')->orderBy('admission_no')->get()
            ->map(fn (Student $s) => array_merge(StudentController::present($s), [
                'enrollment' => $s->pivot->only(['enrolled_on', 'discount_percent', 'status']),
            ]));

        return array_merge($batch->toArray(), [
            'subject_name' => $batch->subject?->name,
            'teacher_name' => $batch->teacher?->user?->name,
            'students' => $students,
        ]);
    }

    public function update(Request $request, Batch $batch)
    {
        $batch->update($this->validateData($request));

        return $batch;
    }

    public function destroy(Batch $batch)
    {
        // Deleting cascades away classes, attendance, exams, results, lessons and discussions.
        // Only allow it for batches that were set up by mistake and have no history yet.
        $history = array_filter([
            'attendance records' => \App\Models\Attendance::whereIn('class_session_id', $batch->sessions()->select('id'))->exists(),
            'exam results' => \App\Models\ExamAttempt::whereIn('exam_id', \App\Models\Exam::where('batch_id', $batch->id)->select('id'))->exists(),
            'fee invoices' => \App\Models\FeeInvoice::where('batch_id', $batch->id)->exists(),
            'lessons' => \App\Models\Lesson::where('batch_id', $batch->id)->exists(),
            'discussions' => \App\Models\Thread::where('batch_id', $batch->id)->exists(),
            'certificates' => \App\Models\Certificate::where('batch_id', $batch->id)->exists(),
        ]);
        abort_if($history, 422, 'This batch has '.implode(', ', array_keys($history))
            .', so deleting it would erase that history. Edit the batch and untick "Active" to close it instead.');

        AuditLogger::log('batch.deleted', $batch, ['name' => $batch->name]);
        $batch->delete();

        return response()->noContent();
    }

    public function enroll(Request $request, Batch $batch)
    {
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', Rule::exists('students', 'id')->where('institute_id', $this->user()->institute_id)],
            'discount_percent' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $existing = $batch->activeStudents()->count();
        $new = collect($data['student_ids'])->diff($batch->students()->pluck('students.id'));
        if ($batch->capacity && $existing + $new->count() > $batch->capacity) {
            abort(422, "This batch is limited to {$batch->capacity} students.");
        }

        $sync = [];
        foreach ($data['student_ids'] as $id) {
            $sync[$id] = ['enrolled_on' => now()->toDateString(), 'status' => 'active', 'discount_percent' => $data['discount_percent'] ?? 0];
        }
        $batch->students()->syncWithoutDetaching($sync);

        return response()->json(['message' => 'Students enrolled', 'count' => count($sync)]);
    }

    public function updateEnrollment(Request $request, Batch $batch, Student $student)
    {
        $data = $request->validate([
            'discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $batch->students()->updateExistingPivot($student->id, array_filter($data, fn ($v) => $v !== null));

        return response()->json(['message' => 'Enrollment updated']);
    }

    public function unenroll(Batch $batch, Student $student)
    {
        $batch->students()->detach($student->id);

        return response()->noContent();
    }

    private function validateData(Request $request): array
    {
        $iid = $this->user()->institute_id;

        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'subject_id' => ['nullable', Rule::exists('subjects', 'id')->where('institute_id', $iid)],
            'teacher_id' => ['nullable', Rule::exists('teachers', 'id')->where('institute_id', $iid)],
            'grade' => ['nullable', 'string', 'max:20'],
            'medium' => ['required', Rule::in(config('tuitionpro.mediums'))],
            'mode' => ['required', Rule::in(['physical', 'online', 'hybrid'])],
            'monthly_fee' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'room' => ['nullable', 'string', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
