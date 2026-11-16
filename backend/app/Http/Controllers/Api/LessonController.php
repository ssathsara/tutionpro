<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class LessonController extends Controller
{
    public function __construct(private Notifier $notifier) {}

    public function index(Request $request)
    {
        $user = $this->user();
        $ids = $user->visibleBatchIds();

        $q = Lesson::query()->with('batch:id,name')
            ->when($ids !== null, fn ($q) => $q->whereIn('batch_id', $ids))
            ->when(! $user->isStaff(), fn ($q) => $q->where('is_published', true))
            ->when($request->batch_id, fn ($q, $b) => $q->where('batch_id', $b))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->search, fn ($q, $s) => $q->where('title', 'like', "%$s%"))
            ->orderBy('batch_id')->orderBy('position')->orderByDesc('id');

        $completed = [];
        if ($user->role === User::STUDENT && $user->student) {
            $completed = DB::table('lesson_progress')->where('student_id', $user->student->id)
                ->whereNotNull('completed_at')->pluck('lesson_id')->flip()->all();
        }

        return $q->paginate($this->perPage($request, 50))->through(fn (Lesson $l) => array_merge($l->toArray(), [
            'batch_name' => $l->batch?->name,
            'completed' => isset($completed[$l->id]),
        ]));
    }

    public function show(Lesson $lesson)
    {
        $this->ensureBatch($lesson->batch_id);
        abort_if(! $lesson->is_published && ! $this->user()->isStaff(), 404);

        return array_merge($lesson->load('batch:id,name')->toArray(), ['batch_name' => $lesson->batch?->name]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $this->ensureBatch((int) $data['batch_id']);
        $data = $this->handleUpload($request, $data);
        $lesson = Lesson::create(array_merge($data, ['created_by' => $this->user()->id]));

        if ($lesson->is_published) {
            $students = $lesson->batch->activeStudents()->with('user')->get();
            $this->notifier->notify($students->pluck('user'), 'lesson', 'New '.$lesson->type.': '.$lesson->title, $lesson->batch->name, '/lessons');
        }

        return response()->json($lesson, 201);
    }

    /** Accepts POST (multipart) or PUT. */
    public function update(Request $request, Lesson $lesson)
    {
        $this->ensureBatch($lesson->batch_id);
        $data = $this->validateData($request);
        $this->ensureBatch((int) $data['batch_id']);
        $data = $this->handleUpload($request, $data, $lesson);
        $lesson->update($data);

        return $lesson;
    }

    public function destroy(Lesson $lesson)
    {
        $this->ensureBatch($lesson->batch_id);
        $this->deleteFile($lesson->file_path);
        $lesson->delete();

        return response()->noContent();
    }

    /**
     * Download/view an attachment. Requires login and access to the lesson's batch;
     * students and parents only get published lessons.
     */
    public function file(Lesson $lesson)
    {
        $this->ensureBatch($lesson->batch_id);
        abort_if(! $lesson->is_published && ! $this->user()->isStaff(), 404);
        abort_unless($lesson->file_path, 404, 'This lesson has no attachment.');

        $disk = Storage::disk(Lesson::FILE_DISK);
        if (! $disk->exists($lesson->file_path)) {
            // Files uploaded before attachments became private were on the public disk: move them.
            $public = Storage::disk('public');
            abort_unless($public->exists($lesson->file_path), 404, 'The attachment file is missing.');
            $disk->writeStream($lesson->file_path, $public->readStream($lesson->file_path));
            $public->delete($lesson->file_path);
        }

        $name = $lesson->file_name ?: basename($lesson->file_path);
        $inline = (bool) preg_match('/\.(pdf|png|jpe?g)$/i', $name);

        return $disk->response($lesson->file_path, $name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $inline ? 'inline' : 'attachment');
    }

    private function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk(Lesson::FILE_DISK)->delete($path);
            Storage::disk('public')->delete($path);
        }
    }

    public function complete(Lesson $lesson)
    {
        $user = $this->user();
        abort_unless($user->role === User::STUDENT && $user->student, 403);
        $this->ensureBatch($lesson->batch_id);
        abort_unless($lesson->is_published, 404); // hidden drafts must not count towards progress or the leaderboard
        DB::table('lesson_progress')->updateOrInsert(
            ['lesson_id' => $lesson->id, 'student_id' => $user->student->id],
            ['completed_at' => now(), 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['message' => 'Marked as complete']);
    }

    /** Per-student completion for a batch (staff). */
    public function progress(Request $request)
    {
        $data = $request->validate(['batch_id' => ['required', 'integer']]);
        $this->ensureBatch((int) $data['batch_id']);
        $lessonIds = Lesson::where('batch_id', $data['batch_id'])->where('is_published', true)->pluck('id');
        $done = DB::table('lesson_progress')->whereIn('lesson_id', $lessonIds)->whereNotNull('completed_at')
            ->selectRaw('student_id, count(*) as c')->groupBy('student_id')->pluck('c', 'student_id');

        $students = Student::whereHas('batches', fn ($q) => $q->where('batches.id', $data['batch_id']))->with('user:id,name')->get();

        return [
            'total_lessons' => $lessonIds->count(),
            'students' => $students->map(fn (Student $s) => [
                'student_id' => $s->id,
                'name' => $s->user?->name,
                'completed' => (int) ($done[$s->id] ?? 0),
                'percent' => $lessonIds->count() ? round(($done[$s->id] ?? 0) / $lessonIds->count() * 100) : 0,
            ])->sortByDesc('completed')->values(),
        ];
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')->where('institute_id', $this->user()->institute_id)],
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(Lesson::TYPES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'content' => ['nullable', 'string', 'max:100000'],
            'video_url' => ['nullable', 'url', 'max:255', 'required_if:type,video'],
            'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,png,jpg,jpeg,zip'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['sometimes', 'boolean'],
        ]);
    }

    private function handleUpload(Request $request, array $data, ?Lesson $existing = null): array
    {
        unset($data['file']);
        if ($request->hasFile('file')) {
            $this->deleteFile($existing?->file_path);
            $file = $request->file('file');
            $data['file_path'] = $file->store('lessons/'.$this->user()->institute_id, Lesson::FILE_DISK);
            $data['file_name'] = mb_substr($file->getClientOriginalName(), 0, 250);
        }

        return $data;
    }
}
