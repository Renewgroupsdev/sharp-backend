<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

/** Base for API controllers: keeps the legacy {"ok": true, ...} response envelope. */
abstract class ApiController extends Controller
{
    use AuthorizesRequests;

    protected function ok(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['ok' => true] + $data, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
