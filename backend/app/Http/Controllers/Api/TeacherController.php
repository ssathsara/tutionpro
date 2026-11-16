<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    public static function present(Teacher $t): array
    {
        return array_merge($t->toArray(), [
            'name' => $t->user?->name,
            'email' => $t->user?->email,
            'phone' => $t->user?->phone,
            'is_active' => $t->user?->is_active,
        ]);
    }

    public function index(Request $request)
    {
        $q = Teacher::query()->with('user:id,name,email,phone,is_active')->withCount('batches')
            ->when($request->search, fn ($q, $s) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")))
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $q->get()->map(fn ($t) => self::present($t));
        }

        return $q->paginate($this->perPage($request))->through(fn ($t) => self::present($t));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $password = $data['password'] ?? $this->generatePassword();
        $teacher = DB::transaction(function () use ($data, $password) {
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $password,
                'role' => User::TEACHER,
            ]);

            return Teacher::create(array_merge($this->profile($data), ['user_id' => $user->id]));
        });
        AuditLogger::log('teacher.created', $teacher);

        $credentials = empty($data['password'])
            ? [['role' => 'teacher', 'name' => $data['name'], 'login' => strtolower($data['email']), 'password' => $password]]
            : [];

        return response()->json(array_merge(self::present($teacher->load('user')), ['credentials' => $credentials]), 201);
    }

    public function show(Teacher $teacher)
    {
        return array_merge(self::present($teacher->load('user', 'batches.subject')), []);
    }

    public function update(Request $request, Teacher $teacher)
    {
        $data = $this->validateData($request, $teacher);
        DB::transaction(function () use ($teacher, $data) {
            $teacher->user->update(array_filter([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'] ?? null,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null,
            ], fn ($v) => $v !== null));
            $teacher->update($this->profile($data));
        });

        return self::present($teacher->fresh('user'));
    }

    public function destroy(Teacher $teacher)
    {
        // Deleting the user would cascade away every discussion thread and answer they wrote.
        $hasHistory = \App\Models\Thread::where('user_id', $teacher->user_id)->exists()
            || \App\Models\ThreadReply::where('user_id', $teacher->user_id)->exists();
        abort_if($hasHistory, 422, 'This teacher has answered student discussions, so deleting them would erase that history. Untick "Account active" to deactivate them instead.');
        AuditLogger::log('teacher.deleted', $teacher, ['name' => $teacher->user?->name]);
        DB::transaction(function () use ($teacher) {
            $user = $teacher->user;
            $teacher->delete();
            $user?->delete();
        });

        return response()->noContent();
    }

    private function profile(array $data): array
    {
        return collect($data)->only(['nic', 'qualification', 'specialization', 'payment_rate', 'joined_on', 'bio'])->all();
    }

    private function validateData(Request $request, ?Teacher $teacher = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($teacher?->user_id)],
            'phone' => ['nullable', 'regex:'.Phone::SL_MOBILE_REGEX],
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
            // Sri Lankan NIC: old (9 digits + V/X) or new (12 digits)
            'nic' => ['nullable', 'regex:/^(\d{9}[VvXx]|\d{12})$/'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'payment_rate' => ['nullable', 'numeric', 'between:0,100'],
            'joined_on' => ['nullable', 'date'],
            'bio' => ['nullable', 'string', 'max:2000'],
        ], [
            'phone.regex' => 'Enter a valid Sri Lankan mobile number (e.g. 0771234567).',
            'nic.regex' => 'Enter a valid NIC number (e.g. 199012345678 or 901234567V).',
        ]);
    }
}
