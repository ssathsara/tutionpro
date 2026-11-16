<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Institute;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Platform (super admin) management of tenants. */
class InstituteController extends Controller
{
    public function index(Request $request)
    {
        $q = Institute::query()
            ->withCount([
                'users as students_count' => fn ($q) => $q->where('role', User::STUDENT),
                'users as teachers_count' => fn ($q) => $q->where('role', User::TEACHER),
            ])
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('city', 'like', "%$s%")))
            ->latest();

        return $q->paginate($this->perPage($request));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'regex:'.Phone::SL_ANY_REGEX],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'plan' => ['nullable', Rule::in(['starter', 'growth', 'enterprise'])],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'unique:users,email'],
            'admin_password' => ['required', Password::min(8)],
        ]);

        $institute = DB::transaction(function () use ($data) {
            $base = Str::slug($data['name']) ?: 'institute';
            $slug = $base;
            $i = 1;
            while (Institute::where('slug', $slug)->exists()) {
                $slug = $base.'-'.(++$i);
            }
            $institute = Institute::create([
                'name' => $data['name'], 'slug' => $slug,
                'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null, 'city' => $data['city'] ?? null,
                'district' => $data['district'] ?? null, 'plan' => $data['plan'] ?? 'starter',
            ]);
            User::create([
                'institute_id' => $institute->id,
                'name' => $data['admin_name'],
                'email' => strtolower($data['admin_email']),
                'password' => $data['admin_password'],
                'role' => User::ADMIN,
            ]);

            return $institute;
        });

        return response()->json($institute, 201);
    }

    public function show(Institute $institute)
    {
        return $institute->loadCount('users');
    }

    public function update(Request $request, Institute $institute)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'regex:'.Phone::SL_ANY_REGEX],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'plan' => ['nullable', Rule::in(['starter', 'growth', 'enterprise'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $institute->update($data);

        return $institute;
    }

    public function destroy(Institute $institute)
    {
        $institute->delete();

        return response()->noContent();
    }

    public function stats()
    {
        return [
            'institutes' => Institute::count(),
            'active_institutes' => Institute::where('is_active', true)->count(),
            'students' => Student::withoutGlobalScopes()->count(),
            'teachers' => User::where('role', User::TEACHER)->count(),
            'batches' => Batch::withoutGlobalScopes()->count(),
            'revenue_this_month' => (float) Payment::withoutGlobalScopes()->where('status', 'completed')
                ->where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
            'recent_institutes' => Institute::latest()->limit(5)->get(['id', 'name', 'city', 'plan', 'created_at']),
        ];
    }
}
