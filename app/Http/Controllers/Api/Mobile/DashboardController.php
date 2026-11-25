<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\ApiController;

use App\Http\Resources\AuditLogResource;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends ApiController
{
    public function __invoke(DashboardService $dashboard): JsonResponse
    {
        $stats = $dashboard->stats();
        $stats['recent_activity'] = AuditLogResource::collection($stats['recent_activity']);

        return $this->ok($stats);
    }
}
