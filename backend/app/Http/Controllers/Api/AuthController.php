<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institute;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function registerInstitute(Request $request)
    {
        $data = $request->validate([
            'institute_name' => ['required', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'institute_phone' => ['nullable', 'regex:'.Phone::SL_ANY_REGEX],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'regex:'.Phone::SL_MOBILE_REGEX],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'institute_phone.regex' => 'Enter a valid Sri Lankan phone number (e.g. 0112345678 or 0771234567).',
            'phone.regex' => 'Enter a valid Sri Lankan mobile number (e.g. 0771234567).',
        ]);

        [$institute, $user] = DB::transaction(function () use ($data) {
            $institute = Institute::create([
                'name' => $data['institute_name'],
                'slug' => $this->uniqueSlug($data['institute_name']),
                'city' => $data['city'] ?? null,
                'phone' => $data['institute_phone'] ?? null,
                'email' => $data['email'],
            ]);
            $user = User::create([
                'institute_id' => $institute->id,
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'role' => User::ADMIN,
            ]);

            return [$institute, $user];
        });

        $token = $user->createToken('web')->plainTextToken;

        return response()->json(['token' => $token, 'user' => $user->toAuthArray()], 201);
    }

    public function login(Request $request)
    {
        // "email" is the login identifier: a real email, or a generated username such as
        // st20260025@pragathi for students registered without an email address.
        $data = $request->validate([
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
        ]);

        $user = User::withoutGlobalScopes()->where('email', strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Your account has been deactivated.']);
        }
        if ($user->institute_id && ! $user->institute?->is_active) {
            throw ValidationException::withMessages(['email' => 'This institute is suspended.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('web')->plainTextToken;

        auth()->setUser($user);
        AuditLogger::log('auth.login', $user);

        return response()->json(['token' => $token, 'user' => $user->toAuthArray()]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()->toAuthArray()]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'regex:'.Phone::SL_MOBILE_REGEX],
        ], ['regex' => 'Enter a valid Sri Lankan mobile number (e.g. 0771234567).']);
        $user->update($data);

        return response()->json(['user' => $user->toAuthArray()]);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $user->update(['password' => $data['password']]);
        // Revoke every other session token.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();
        AuditLogger::log('auth.password_changed', $user);

        return response()->json(['message' => 'Password updated']);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'institute';
        $slug = $base;
        $i = 1;
        while (Institute::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
