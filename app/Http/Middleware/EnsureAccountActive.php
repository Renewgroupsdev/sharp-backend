<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuspended()) {
            throw new AuthorizationException('This account has been suspended.');
        }

        // Presence for the chat's "online": note the time of use, at most once a minute (a plain query, so updated_at is untouched).
        $user = $request->user();
        if ($user && (! $user->last_seen_at || $user->last_seen_at->diffInSeconds(now()) >= 60)) {
            DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now()]);
            $user->setAttribute('last_seen_at', now());
        }

        return $next($request);
    }
}
