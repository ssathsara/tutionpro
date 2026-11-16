<?php

namespace App\Providers;

use App\Support\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Tenant::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(false);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        // Login protection:
        //  - per account (from any IP): 10 attempts/minute stops password guessing on one account.
        //  - per IP: generous, because whole classes share one public IP (school labs, home Wi-Fi,
        //    and Sri Lankan mobile networks use carrier-grade NAT). The old 30/minute limit locked
        //    out 250 of 300 students logging in together for an exam.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('acct:'.strtolower(trim((string) $request->input('email'))))
                ->response(fn () => response()->json(['message' => 'Too many login attempts for this account. Please wait a minute and try again.'], 429)),
            Limit::perMinute((int) config('tuitionpro.login_attempts_per_ip', 600))->by('ip:'.$request->ip())
                ->response(fn () => response()->json(['message' => 'Too many login attempts from your network. Please wait a minute and try again.'], 429)),
        ]);
    }
}
