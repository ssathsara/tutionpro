<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClassSessionController extends Controller
{
    public function __construct(private Notifier $notifier) {}

    public function index(Request $request)
    {
        $ids = $this->user()->visibleBatchIds();

        $q = ClassSession::query()
            ->with(['batch:id,name,teacher_id,subject_id', 'batch.teacher.user:id,name', 'batch.subject:id,name'])
            ->withCount([
                'attendances as present_count' => fn ($q) => $q->whereIn('status', ['present', 'late']),
                'attendances as marked_count',
            ])
            ->when($ids !== null, fn ($q) => $q->whereIn('batch_id', $ids))
            ->when($request->batch_id, fn ($q, $b) => $q->where('batch_id', $b))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->from, fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('date', '<=', $d));

        if ($request->boolean('upcoming')) {
            $q->whereDate('date', '>=', today())->where('status', '!=', 'cancelled')->orderBy('date')->orderBy('start_time');
        } else {
            $q->orderByDesc('date')->orderByDesc('start_time');
        }

        return $q->paginate($this->perPage($request))->through(fn (ClassSession $s) => $this->present($s));
    }

    private function present(ClassSession $s): array
    {
        $data = array_merge($s->toArray(), [
            'batch_name' => $s->batch?->name,
            'teacher_name' => $s->batch?->teacher?->user?->name,
            'subject_name' => $s->batch?->subject?->name,
        ]);
        // Learners only receive the meeting link through the join endpoint (records attendance).
        if (! $this->user()->isStaff()) {
            unset($data['meeting_url']);
            $data['can_join'] = $s->type === 'live' && $s->status === 'scheduled';
        }

        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $this->ensureBatch((int) $data['batch_id']);
        if ($data['type'] === 'live' && empty($data['meeting_url'])) {
            $data['meeting_url'] = $this->jitsiUrl();
        }
        $session = ClassSession::create($data);

        if ($session->type === 'live') {
            $this->notifyBatch($session, 'New live class scheduled',
                sprintf('%s on %s at %s', $session->title ?: $session->batch->name, $session->date->format('d M'), substr($session->start_time, 0, 5)));
        }

        return response()->json($this->present($session->load('batch')), 201);
    }

    public function show(ClassSession $classSession)
    {
        $this->ensureBatch($classSession->batch_id);

        return $this->present($classSession->load('batch.teacher.user'));
    }

    public function update(Request $request, ClassSession $classSession)
    {
        $this->ensureBatch($classSession->batch_id);
        $data = $this->validateData($request);
        $this->ensureBatch((int) $data['batch_id']);
        if ($data['type'] === 'live' && empty($data['meeting_url'])) {
            $data['meeting_url'] = $classSession->meeting_url ?: $this->jitsiUrl();
        }
        $wasCancelled = $classSession->status === 'cancelled';
        $classSession->update($data);

        if (! $wasCancelled && $classSession->status === 'cancelled') {
            $this->notifyBatch($classSession, 'Class cancelled',
                sprintf('%s on %s has been cancelled', $classSession->batch->name, $classSession->date->format('d M')), true);
        }

        return $this->present($classSession);
    }

    public function destroy(ClassSession $classSession)
    {
        $this->ensureBatch($classSession->batch_id);
        $classSession->delete();

        return response()->noContent();
    }

    /** Create sessions for a date range from the weekly timetable. */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'batch_id' => ['nullable', 'integer'],
        ]);
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        abort_if($from->diffInDays($to) > 62, 422, 'Generate at most two months at a time.');

        $ids = $this->user()->visibleBatchIds();
        $slots = TimetableSlot::with('batch')
            ->when($ids !== null, fn ($q) => $q->whereIn('batch_id', $ids))
            ->when($data['batch_id'] ?? null, fn ($q, $b) => $q->where('batch_id', $b))
            ->get();

        $created = 0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            foreach ($slots->where('day_of_week', $d->dayOfWeek) as $slot) {
                $exists = ClassSession::where('batch_id', $slot->batch_id)->whereDate('date', $d)
                    ->where('start_time', $slot->start_time)->exists();
                if ($exists) {
                    continue;
                }
                $online = $slot->batch->mode === 'online';
                ClassSession::create([
                    'batch_id' => $slot->batch_id,
                    'date' => $d->toDateString(),
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'type' => $online ? 'live' : 'physical',
                    'meeting_url' => $online ? $this->jitsiUrl() : null,
                ]);
                $created++;
            }
        }

        return response()->json(['message' => "$created sessions created", 'created' => $created]);
    }

    /** Roster for marking attendance. */
    public function attendance(ClassSession $classSession)
    {
        $this->ensureBatch($classSession->batch_id);
        $classSession->load('batch');
        $marked = $classSession->attendances()->get()->keyBy('student_id');

        $students = $classSession->batch->activeStudents()->with('user:id,name')->orderBy('admission_no')->get()
            ->map(fn (Student $s) => [
                'student_id' => $s->id,
                'name' => $s->user?->name,
                'admission_no' => $s->admission_no,
                'status' => $marked[$s->id]->status ?? null,
                'method' => $marked[$s->id]->method ?? null,
                'remarks' => $marked[$s->id]->remarks ?? null,
            ]);

        return ['session' => $this->present($classSession), 'students' => $students];
    }

    public function markAttendance(Request $request, ClassSession $classSession)
    {
        $this->ensureBatch($classSession->batch_id);
        $data = $request->validate([
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_id' => ['required', 'integer'],
            'records.*.status' => ['required', Rule::in(Attendance::STATUSES)],
            'records.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $enrolled = $classSession->batch->students()->pluck('students.id')->all();
        $newlyAbsent = [];

        DB::transaction(function () use ($data, $classSession, $enrolled, &$newlyAbsent) {
            foreach ($data['records'] as $r) {
                if (! in_array((int) $r['student_id'], $enrolled, true)) {
                    continue;
                }
                $att = Attendance::firstOrNew(['class_session_id' => $classSession->id, 'student_id' => $r['student_id']]);
                if ($r['status'] === 'absent' && $att->status !== 'absent') {
                    $newlyAbsent[] = (int) $r['student_id'];
                }
                $att->fill(['status' => $r['status'], 'remarks' => $r['remarks'] ?? null, 'marked_by' => $this->user()->id]);
                if (! $att->exists) {
                    $att->method = 'manual';
                }
                $att->save();
            }
            if ($classSession->status === 'scheduled' && $classSession->date->lte(today())) {
                $classSession->update(['status' => 'completed']);
            }
        });

        // Alert guardians about absences by SMS and in-app.
        if ($newlyAbsent) {
            $students = Student::with('user:id,name', 'guardians')->whereIn('id', $newlyAbsent)->get();
            foreach ($students as $s) {
                $this->notifier->notify($s->guardians, 'attendance', 'Absence alert',
                    sprintf('%s was absent from %s on %s.', $s->user?->name, $classSession->batch->name, $classSession->date->format('d M Y')),
                    '/my-attendance', true);
            }
        }

        return response()->json(['message' => 'Attendance saved', 'absent_alerts' => count($newlyAbsent)]);
    }

    /** Student joins a live class: returns the link and records online attendance. */
    public function join(ClassSession $classSession)
    {
        $user = $this->user();
        $this->ensureBatch($classSession->batch_id);
        abort_unless($classSession->type === 'live' && $classSession->meeting_url, 422, 'This is not a live class.');
        abort_if($classSession->status === 'cancelled', 422, 'This class was cancelled.');

        if ($user->role === User::STUDENT && $user->student) {
            // The room opens 15 minutes before the start and closes at the end time (or 3 hours after start).
            $start = Carbon::parse($classSession->date->toDateString().' '.$classSession->start_time);
            $end = $classSession->end_time
                ? Carbon::parse($classSession->date->toDateString().' '.$classSession->end_time)
                : $start->copy()->addHours(3);
            abort_if(now()->lt($start->copy()->subMinutes(15)), 422,
                'This class has not started yet. You can join from '.$start->copy()->subMinutes(15)->format('d M Y, h:i A').'.');
            abort_if(now()->gt($end), 422, 'This class has already ended.'.($classSession->recording_url ? ' Watch the recording instead.' : ''));

            $att = Attendance::firstOrNew(['class_session_id' => $classSession->id, 'student_id' => $user->student->id]);
            if (! $att->exists || $att->status === 'absent') {
                $att->fill(['status' => now()->gt($start->copy()->addMinutes(15)) ? 'late' : 'present', 'method' => 'online'])->save();
            }
        }

        return ['meeting_url' => $classSession->meeting_url];
    }

    /** Attendance history for one student (staff, the student or their parent). */
    public function studentAttendance(Request $request, Student $student)
    {
        $this->ensureStudent($student->id);
        $records = Attendance::where('student_id', $student->id)
            ->with(['session:id,batch_id,date,start_time,title,type', 'session.batch:id,name'])
            ->when($request->batch_id, fn ($q, $b) => $q->whereHas('session', fn ($w) => $w->where('batch_id', $b)))
            ->latest('id')->limit(200)->get();

        $counts = $records->countBy('status');
        $total = $records->count();

        return [
            'student' => ['id' => $student->id, 'name' => $student->user?->name, 'admission_no' => $student->admission_no],
            'summary' => [
                'total' => $total,
                'present' => $counts['present'] ?? 0,
                'late' => $counts['late'] ?? 0,
                'absent' => $counts['absent'] ?? 0,
                'excused' => $counts['excused'] ?? 0,
                'rate' => $total ? round((($counts['present'] ?? 0) + ($counts['late'] ?? 0)) / $total * 100, 1) : null,
            ],
            'records' => $records,
        ];
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')->where('institute_id', $this->user()->institute_id)],
            'title' => ['nullable', 'string', 'max:200'],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'end_time' => ['nullable', 'date_format:H:i,H:i:s', 'after:start_time'],
            'type' => ['required', Rule::in(['physical', 'live'])],
            'meeting_url' => ['nullable', 'url', 'max:255'],
            'recording_url' => ['nullable', 'url', 'max:255'],
            'status' => ['nullable', Rule::in(['scheduled', 'completed', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        foreach (['start_time', 'end_time'] as $k) {
            if (! empty($data[$k])) {
                $data[$k] = substr($data[$k], 0, 5).':00';
            }
        }
        $data['status'] ??= 'scheduled';

        return $data;
    }

    private function jitsiUrl(): string
    {
        $slug = Str::slug($this->user()->institute?->slug ?? 'tuitionpro');

        return 'https://meet.jit.si/tuitionpro-'.$slug.'-'.Str::lower(Str::random(10));
    }

    private function notifyBatch(ClassSession $session, string $title, string $body, bool $sms = false): void
    {
        $students = Batch::find($session->batch_id)->activeStudents()->with('user', 'guardians')->get();
        $users = $students->flatMap(fn (Student $s) => $s->notifiableUsers());
        $this->notifier->notify($users, 'live_class', $title, $body, '/live', $sms);
    }
}
