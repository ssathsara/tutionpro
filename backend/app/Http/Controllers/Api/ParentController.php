<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ParentController extends Controller
{
    private function present(User $p): array
    {
        return array_merge($p->only(['id', 'name', 'email', 'phone', 'is_active', 'created_at']), [
            'children' => $p->children->map(fn ($s) => ['id' => $s->id, 'name' => $s->user?->name, 'admission_no' => $s->admission_no])->values(),
            'student_ids' => $p->children->pluck('id')->values(),
        ]);
    }

    public function index(Request $request)
    {
        $q = User::where('role', User::PARENT)->with('children.user:id,name')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")))
            ->orderByDesc('id');

        return $q->paginate($this->perPage($request))->through(fn ($p) => $this->present($p));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $password = $data['password'] ?? $this->generatePassword();
        $parent = DB::transaction(function () use ($data, $password) {
            $parent = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $password,
                'role' => User::PARENT,
            ]);
            $parent->children()->sync($data['student_ids'] ?? []);

            return $parent;
        });

        $credentials = empty($data['password'])
            ? [['role' => 'parent', 'name' => $parent->name, 'login' => $parent->email, 'password' => $password]]
            : [];

        return response()->json(array_merge($this->present($parent->load('children.user')), ['credentials' => $credentials]), 201);
    }

    public function update(Request $request, User $parent)
    {
        abort_unless($parent->role === User::PARENT, 404);
        $data = $this->validateData($request, $parent);
        $parent->update(array_filter([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'] ?? null,
        ], fn ($v) => $v !== null));
        if (array_key_exists('student_ids', $data)) {
            $parent->children()->sync($data['student_ids'] ?? []);
        }

        return $this->present($parent->fresh('children.user'));
    }

    public function destroy(User $parent)
    {
        abort_unless($parent->role === User::PARENT, 404);
        $parent->delete();

        return response()->noContent();
    }

    /** Parent portal: list my children with a quick status overview. */
    public function children()
    {
        $user = $this->user();
        abort_unless($user->role === User::PARENT, 403);

        return $user->children()->with('user:id,name', 'batches:id,name')->get()->map(function (Student $s) {
            $att = $s->attendances()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
            $total = $att->sum();

            return [
                'id' => $s->id,
                'name' => $s->user?->name,
                'admission_no' => $s->admission_no,
                'grade' => $s->grade,
                'batches' => $s->batches->pluck('name'),
                'attendance_rate' => $total ? round((($att['present'] ?? 0) + ($att['late'] ?? 0)) / $total * 100, 1) : null,
                'outstanding' => round((float) $s->invoices()->whereIn('status', ['unpaid', 'partial'])->sum(DB::raw('amount - discount - paid_amount')), 2),
                'latest_results' => $s->attempts()->where('status', 'submitted')->with('exam:id,title')
                    ->latest('submitted_at')->limit(3)->get(['id', 'exam_id', 'percentage', 'score', 'total_marks', 'submitted_at']),
            ];
        });
    }

    private function validateData(Request $request, ?User $parent = null): array
    {
        $iid = $this->user()->institute_id;

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($parent?->id)],
            'phone' => ['nullable', 'regex:'.Phone::SL_MOBILE_REGEX],
            'password' => ['nullable', 'string', 'min:8'],
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['integer', Rule::exists('students', 'id')->where('institute_id', $iid)],
        ], ['phone.regex' => 'Enter a valid Sri Lankan mobile number (e.g. 0771234567).']);
    }
}
