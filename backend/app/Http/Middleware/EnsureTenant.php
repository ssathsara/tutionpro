<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's institute as the current tenant and blocks
 * deactivated users or suspended institutes.
 */
class EnsureTenant
{
    public function __construct(private Tenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Your account has been deactivated.'], 403);
        }

        // Always (re)bind so a long-lived worker never leaks a previous tenant.
        $this->tenant->set($user->institute_id);

        if ($user->institute_id) {
            $institute = $user->institute;
            if (! $institute || ! $institute->is_active) {
                return response()->json(['message' => 'This institute is suspended. Contact TuitionPro support.'], 403);
            }
        }

        return $next($request);
    }
}
