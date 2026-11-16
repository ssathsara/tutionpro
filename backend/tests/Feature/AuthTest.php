<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_institute_can_register_and_receives_token(): void
    {
        $res = $this->postJson('/api/auth/register-institute', [
            'institute_name' => 'Sipwin Institute',
            'city' => 'Galle',
            'name' => 'Ruwan Silva',
            'email' => 'ruwan@sipwin.lk',
            'phone' => '0712345678',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $res->assertCreated()->assertJsonPath('user.role', 'institute_admin')->assertJsonStructure(['token']);
        $this->assertDatabaseHas('institutes', ['name' => 'Sipwin Institute', 'slug' => 'sipwin-institute']);
    }

    public function test_registration_rejects_invalid_sri_lankan_phone(): void
    {
        $this->postJson('/api/auth/register-institute', [
            'institute_name' => 'X', 'name' => 'Y', 'email' => 'y@x.lk', 'phone' => '12345',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_login_and_me(): void
    {
        [, $admin] = $this->makeInstitute();

        $token = $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password1'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $admin->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $admin->id]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        [, $admin] = $this->makeInstitute();
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_deactivated_user_cannot_login(): void
    {
        [$inst] = $this->makeInstitute();
        $u = $this->makeUser($inst, User::TEACHER, ['is_active' => false]);
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'password1'])->assertStatus(422);
    }

    public function test_suspended_institute_blocks_existing_tokens(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $token = $admin->createToken('t')->plainTextToken;
        $inst->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/students')->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/students')->assertUnauthorized();
    }

    public function test_a_whole_class_behind_one_ip_can_log_in_together(): void
    {
        [$inst] = $this->makeInstitute();
        $students = collect(range(1, 60))->map(fn () => $this->makeUser($inst, User::STUDENT));

        foreach ($students as $s) {
            $this->postJson('/api/auth/login', ['email' => $s->email, 'password' => 'password1'], ['REMOTE_ADDR' => '203.0.113.7'])->assertOk();
        }
    }

    public function test_password_guessing_on_one_account_is_still_blocked_from_any_ip(): void
    {
        [, $admin] = $this->makeInstitute();
        foreach (range(1, 10) as $i) {
            $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'wrong'], ['REMOTE_ADDR' => "198.51.100.$i"])->assertStatus(422);
        }
        // 11th attempt from yet another IP is refused, even with the right password.
        $this->postJson('/api/auth/login', ['email' => strtoupper($admin->email), 'password' => 'password1'], ['REMOTE_ADDR' => '198.51.100.99'])
            ->assertStatus(429)->assertJsonPath('message', 'Too many login attempts for this account. Please wait a minute and try again.');
    }

    public function test_per_ip_login_limit_is_configurable(): void
    {
        config(['tuitionpro.login_attempts_per_ip' => 3]);
        [$inst] = $this->makeInstitute();
        foreach (range(1, 3) as $i) {
            $u = $this->makeUser($inst, User::STUDENT);
            $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'password1'], ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();
        }
        $u = $this->makeUser($inst, User::STUDENT);
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'password1'], ['REMOTE_ADDR' => '203.0.113.9'])->assertStatus(429);
    }

    public function test_change_password_requires_current_password(): void
    {
        [, $admin] = $this->makeInstitute();
        $token = $admin->createToken('t')->plainTextToken;
        $this->withToken($token)->putJson('/api/auth/password', [
            'current_password' => 'wrong', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertStatus(422);
        $this->withToken($token)->putJson('/api/auth/password', [
            'current_password' => 'password1', 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk();
    }
}
