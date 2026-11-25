<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('role:super_admin,admin,ob') */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $r) => Role::from($r), $roles);

        if (! $request->user()?->hasRole(...$allowed)) {
            throw new AuthorizationException('You do not have permission to do that.');
        }

        return $next($request);
    }
}
