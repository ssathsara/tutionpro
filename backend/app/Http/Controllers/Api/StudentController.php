<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public static function present(Student $s): array
    {
        return array_merge($s->toArray(), [
            'name' => $s->user?->name,
            'email' => $s->user?->email,
            'phone' => $s->user?->phone,
            'is_active' => $s->user?->is_active,
        ]);
    }

    public function index(Request $request)
    {
        $user = $this->user();
        $ids = $user->visibleStudentIds();

        $q = Student::query()->with(['user:id,name,email,phone,is_active', 'batches:id,name'])
            ->when($ids !== null, fn ($q) => $q->whereIn('students.id', $ids))
            // Pickers: load specific students by id (to show labels for saved values) ...
            ->when($request->ids, fn ($q, $list) => $q->whereIn('students.id', array_filter(array_map('intval', explode(',', (string) $list)))))
            // ... or leave out students already in a batch (enroll dialog).
            ->when($request->not_in_batch, fn ($q, $b) => $q->whereDoesntHave('batches', fn ($w) => $w->where('batches.id', $b)))
            ->when($request->batch_id, fn ($q, $b) => $q->whereHas('batches', fn ($w) => $w->where('batches.id', $b)))
            ->when($request->grade, fn ($q, $g) => $q->where('grade', $g))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, function ($q, $s) {
                $q->where(fn ($w) => $w->where('admission_no', 'like', "%$s%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")));
            })
            ->orderByDesc('students.id');

        if ($request->boolean('all')) {
            return $q->limit(1000)->get()->map(fn ($s) => self::present($s));
        }

        return $q->paginate($this->perPage($request))->through(fn ($s) => self::present($s));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $credentials = [];

        $student = DB::transaction(function () use ($data, &$credentials) {
            $admissionNo = $data['admission_no'] ?? $this->nextAdmissionNo();
            // Many school students have no email: give them a username based on their admission number.
            $login = ! empty($data['email']) ? strtolower($data['email']) : $this->studentUsername($admissionNo);
            $password = $data['password'] ?? $this->generatePassword();

            $user = User::create([
                'name' => $data['name'],
                'email' => $login,
                'phone' => $data['phone'] ?? null,
                'password' => $password,
                'role' => User::STUDENT,
            ]);
            if (empty($data['password']) || empty($data['email'])) {
                $credentials[] = ['role' => 'student', 'name' => $user->name, 'login' => $login, 'password' => $password];
            }
            $student = Student::create(array_merge(
                collect($data)->only($this->profileFields())->all(),
                ['user_id' => $user->id, 'admission_no' => $admissionNo],
            ));
            $this->syncBatches($student, $data['batch_ids'] ?? null);
            if ($parentCredential = $this->linkParent($student, $data)) {
                $credentials[] = $parentCredential;
            }

            return $student;
        });

        AuditLogger::log('student.created', $student);

        // Generated passwords are returned once so the admin can hand them over; they are never retrievable later.
        return response()->json(array_merge(self::present($student->load('user', 'batches:id,name')), [
            'credentials' => $credentials,
        ]), 201);
    }

    public function show(Student $student)
    {
        $this->ensureStudent($student->id);

        $student->load(['user', 'batches.subject', 'batches.teacher.user:id,name', 'guardians:id,name,email,phone']);

        $att = $student->attendances()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $totalAtt = $att->sum();

        // Fee information is private to the office and the family; teachers do not receive it.
        $canSeeFees = $this->user()->hasRole(User::ADMIN, User::PARENT);
        $fees = $canSeeFees ? [
            'invoices' => $student->invoices()->with('batch:id,name')->latest('id')->limit(12)->get(),
            'outstanding' => round((float) $student->invoices()->whereIn('status', ['unpaid', 'partial'])
                ->sum(DB::raw('amount - discount - paid_amount')), 2),
        ] : [];

        return array_merge(self::present($student), $fees, [
            'can_see_fees' => $canSeeFees,
            'attendance' => [
                'total' => $totalAtt,
                'present' => (int) ($att['present'] ?? 0) + (int) ($att['late'] ?? 0),
                'absent' => (int) ($att['absent'] ?? 0),
                'rate' => $totalAtt ? round((($att['present'] ?? 0) + ($att['late'] ?? 0)) / $totalAtt * 100, 1) : null,
            ],
            'recent_attendance' => $student->attendances()->with('session:id,batch_id,date,title', 'session.batch:id,name')
                ->latest('id')->limit(10)->get(),
            'exam_results' => $student->attempts()->where('status', 'submitted')
                ->with('exam:id,title,batch_id,pass_percentage,show_results')->latest('submitted_at')->limit(10)->get(),
        ]);
    }

    public function update(Request $request, Student $student)
    {
        $data = $this->validateData($request, $student);

        $credentials = [];
        DB::transaction(function () use ($student, $data, &$credentials) {
            $student->user->update(array_filter([
                'name' => $data['name'],
                'email' => ! empty($data['email']) ? strtolower($data['email']) : null, // blank keeps the current login
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'] ?? null,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
            ], fn ($v) => $v !== null));
            $student->update(collect($data)->only(array_merge($this->profileFields(), ['admission_no']))->all());
            $this->syncBatches($student, $data['batch_ids'] ?? null);
            if ($parentCredential = $this->linkParent($student, $data)) {
                $credentials[] = $parentCredential;
            }
        });

        AuditLogger::log('student.updated', $student);

        return array_merge(self::present($student->fresh(['user', 'batches:id,name'])), ['credentials' => $credentials]);
    }

    public function destroy(Student $student)
    {
        // Deleting would cascade away receipts and distort revenue reports.
        abort_if($student->invoices()->where('paid_amount', '>', 0)->exists() || \App\Models\Payment::where('student_id', $student->id)->exists(), 422,
            'This student has payment records, so they cannot be deleted. Set their status to Inactive or Graduated instead.');
        AuditLogger::log('student.deleted', $student, ['name' => $student->user?->name]);
        DB::transaction(function () use ($student) {
            $user = $student->user;
            $student->delete();
            $user?->delete();
        });

        return response()->noContent();
    }

    private function profileFields(): array
    {
        return ['grade', 'school', 'date_of_birth', 'gender', 'address', 'guardian_name', 'guardian_phone', 'status'];
    }

    private function validateData(Request $request, ?Student $student = null): array
    {
        $msg = ['regex' => 'Enter a valid Sri Lankan mobile number (e.g. 0771234567).'];

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // Optional: a real email, or (shown on edit) the generated username such as st20260025@pragathi.
            'email' => ['nullable', 'string', 'max:150', 'regex:/^[^@\s]+@[^@\s]+$/', Rule::unique('users', 'email')->ignore($student?->user_id)],
            'phone' => ['nullable', 'regex:'.Phone::SL_MOBILE_REGEX],
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
            'admission_no' => ['nullable', 'string', 'max:30',
                Rule::unique('students', 'admission_no')->where('institute_id', $this->user()->institute_id)->ignore($student?->id)],
            'grade' => ['nullable', 'string', 'max:20'],
            'school' => ['nullable', 'string', 'max:150'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'address' => ['nullable', 'string', 'max:255'],
            'guardian_name' => ['nullable', 'string', 'max:120'],
            'guardian_phone' => ['nullable', 'regex:'.Phone::SL_MOBILE_REGEX],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'graduated'])],
            'batch_ids' => ['nullable', 'array'],
            'batch_ids.*' => ['integer', Rule::exists('batches', 'id')->where('institute_id', $this->user()->institute_id)],
            'parent_email' => ['nullable', 'email'],
            'parent_name' => ['nullable', 'required_with:parent_email', 'string', 'max:120'],
            'parent_phone' => ['nullable', 'regex:'.Phone::SL_MOBILE_REGEX],
        ], array_merge($msg, ['email.regex' => 'Enter a valid email address, or leave it blank to generate a username.']));
    }

    /** Globally unique login for students without email, e.g. st20260025@pragathi */
    private function studentUsername(string $admissionNo): string
    {
        $slug = $this->user()->institute?->slug ?? 'institute';
        $base = Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $admissionNo)).'@'.$slug;
        $login = $base;
        $i = 1;
        while (User::withoutGlobalScopes()->where('email', $login)->exists()) {
            $login = $base.'-'.(++$i);
        }

        return $login;
    }

    private function syncBatches(Student $student, ?array $batchIds): void
    {
        if ($batchIds === null) {
            return;
        }
        $sync = [];
        foreach ($batchIds as $id) {
            $sync[$id] = ['enrolled_on' => now()->toDateString(), 'status' => 'active'];
        }
        $student->batches()->syncWithoutDetaching($sync);
        // Note: detach() ignores query constraints, so compute the ids explicitly.
        $remove = $student->batches()->pluck('batches.id')->diff($batchIds)->values()->all();
        if ($remove) {
            $student->batches()->detach($remove);
        }
    }

    /** Links (or creates) the parent account; returns login details when a new account was created. */
    private function linkParent(Student $student, array $data): ?array
    {
        if (empty($data['parent_email'])) {
            return null;
        }
        $email = strtolower($data['parent_email']);
        $parent = User::withoutGlobalScopes()->where('email', $email)->first();
        if ($parent && ($parent->role !== User::PARENT || $parent->institute_id !== $this->user()->institute_id)) {
            abort(422, 'The parent email belongs to another account.');
        }
        $credential = null;
        if (! $parent) {
            $password = $this->generatePassword();
            $parent = User::create([
                'name' => $data['parent_name'],
                'email' => $email,
                'phone' => $data['parent_phone'] ?? null,
                'password' => $password,
                'role' => User::PARENT,
            ]);
            $credential = ['role' => 'parent', 'name' => $parent->name, 'login' => $email, 'password' => $password];
        }
        $parent->children()->syncWithoutDetaching([$student->id]);

        return $credential;
    }

    private function nextAdmissionNo(): string
    {
        $year = now()->format('Y');
        $count = Student::where('admission_no', 'like', "ST{$year}%")->count() + 1;
        do {
            $no = sprintf('ST%s%04d', $year, $count++);
        } while (Student::where('admission_no', $no)->exists());

        return $no;
    }
}
