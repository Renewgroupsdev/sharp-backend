<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Private WebSocket channels are authorised with the same bearer token as the API (POST /broadcasting/auth).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['auth:sanctum', 'active']])
    ->withMiddleware(function (Middleware $middleware): void {
        // API-only app: there is no login page to redirect guests to.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'role' => EnsureRole::class,
            'active' => EnsureAccountActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);

        // Every error uses the legacy envelope: {"ok": false, "error": "..."}.
        $fail = fn (string $message, int $status, array $extra = []) => response()->json(
            ['ok' => false, 'error' => $message] + $extra,
            $status,
            [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $exceptions->render(fn (ValidationException $e) => $fail($e->validator->errors()->first(), 422, ['errors' => $e->errors()]));
        $exceptions->render(fn (AuthenticationException $e) => $fail(
            $e->getMessage() === 'Unauthenticated.' ? 'Not signed in.' : $e->getMessage(), 401,
        ));
        $exceptions->render(fn (AuthorizationException $e) => $fail($e->getMessage() ?: 'You do not have permission to do that.', 403));
        $exceptions->render(fn (ModelNotFoundException $e) => $fail(class_basename($e->getModel()).' not found.', 404));
        $exceptions->render(fn (HttpExceptionInterface $e) => $fail(
            $e->getMessage() ?: 'Request failed.', $e->getStatusCode(),
        ));
    })->create();
