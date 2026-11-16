<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Exam;
use App\Models\Student;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CertificateController extends Controller
{
    public function __construct(private Notifier $notifier) {}

    public function index(Request $request)
    {
        $user = $this->user();
        $studentIds = $user->isStaff() ? null : $user->visibleStudentIds();
        $batchIds = $user->visibleBatchIds();

        return Certificate::query()->with(['student.user:id,name', 'batch:id,name', 'exam:id,title'])
            ->when($studentIds !== null, fn ($q) => $q->whereIn('student_id', $studentIds))
            ->when($studentIds === null && $batchIds !== null, fn ($q) => $q->whereIn('batch_id', $batchIds))
            ->when($request->batch_id, fn ($q, $b) => $q->where('batch_id', $b))
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->through(fn (Certificate $c) => array_merge($c->toArray(), [
                'student_name' => $c->student?->user?->name,
                'batch_name' => $c->batch?->name,
                'exam_title' => $c->exam?->title,
            ]));
    }

    public function store(Request $request)
    {
        $iid = $this->user()->institute_id;
        $data = $request->validate([
            'student_id' => ['required', Rule::exists('students', 'id')->where('institute_id', $iid)],
            'batch_id' => ['required', Rule::exists('batches', 'id')->where('institute_id', $iid)],
            'exam_id' => ['nullable', Rule::exists('exams', 'id')->where('institute_id', $iid)],
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(['completion', 'merit', 'participation'])],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'issued_on' => ['nullable', 'date'],
        ]);
        $this->ensureBatch((int) $data['batch_id']);
        $this->ensureStudent((int) $data['student_id']);

        $cert = $this->issue($data);

        return response()->json($cert, 201);
    }

    /** Issue certificates to every student who passed an exam. */
    public function issueForExam(Request $request, Exam $exam)
    {
        $this->ensureBatch($exam->batch_id);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(['completion', 'merit', 'participation'])],
            'min_percentage' => ['nullable', 'numeric', 'between:0,100'],
        ]);
        $min = $data['min_percentage'] ?? $exam->pass_percentage;

        $studentIds = $exam->attempts()->where('status', 'submitted')->where('percentage', '>=', $min)
            ->distinct()->pluck('student_id');
        $already = Certificate::where('exam_id', $exam->id)->pluck('student_id');
        $issued = 0;
        foreach ($studentIds->diff($already) as $sid) {
            $this->issue([
                'student_id' => $sid, 'batch_id' => $exam->batch_id, 'exam_id' => $exam->id,
                'title' => $data['title'], 'type' => $data['type'],
            ]);
            $issued++;
        }

        return response()->json(['message' => "$issued certificates issued", 'issued' => $issued]);
    }

    public function show(Certificate $certificate)
    {
        $this->ensureStudent($certificate->student_id);

        return $this->present($certificate);
    }

    public function destroy(Certificate $certificate)
    {
        $this->ensureBatch($certificate->batch_id);
        $certificate->delete();

        return response()->noContent();
    }

    /** Public verification by serial number (e.g. printed as a QR / URL on the certificate). */
    public function verify(string $serial)
    {
        $cert = Certificate::withoutGlobalScopes()->where('serial', $serial)->first();
        if (! $cert) {
            return response()->json(['valid' => false, 'message' => 'No certificate found with this serial number.'], 404);
        }

        return array_merge(['valid' => true], $this->present($cert, true));
    }

    private function issue(array $data): Certificate
    {
        $cert = Certificate::create(array_merge($data, [
            'serial' => 'TP-'.now()->format('Y').'-'.Str::upper(Str::random(8)),
            'issued_on' => $data['issued_on'] ?? today(),
            'issued_by' => $this->user()->id,
        ]));
        $student = Student::with('user', 'guardians')->find($cert->student_id);
        $this->notifier->notify($student->notifiableUsers(), 'certificate', 'Certificate awarded', $cert->title, '/certificates');

        return $cert;
    }

    private function present(Certificate $c, bool $public = false): array
    {
        $c->loadMissing(['student' => fn ($q) => $q->withoutGlobalScopes(), 'student.user:id,name', 'batch' => fn ($q) => $q->withoutGlobalScopes(), 'exam' => fn ($q) => $q->withoutGlobalScopes(), 'institute']);
        $issuer = $c->issued_by ? \App\Models\User::withoutGlobalScopes()->find($c->issued_by) : null;

        return [
            'id' => $public ? null : $c->id,
            'serial' => $c->serial,
            'title' => $c->title,
            'type' => $c->type,
            'remarks' => $c->remarks,
            'issued_on' => $c->issued_on?->toDateString(),
            'student_name' => $c->student?->user?->name,
            'admission_no' => $public ? null : $c->student?->admission_no,
            'batch_name' => $c->batch?->name,
            'exam_title' => $c->exam?->title,
            'institute_name' => $c->institute?->name,
            'institute_city' => $c->institute?->city,
            'issued_by_name' => $issuer?->name,
        ];
    }
}
