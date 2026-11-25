<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Services\RoleDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in person's own dashboard: figures and blocks that depend on their role and are scoped to what they may see. */
class RoleDashboardController extends ApiController
{
    public function __invoke(Request $request, RoleDashboardService $dashboards): JsonResponse
    {
        return $this->ok(['dashboard' => $dashboards->for($request->user())]);
    }
}
