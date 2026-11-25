<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The analytics dashboard (staff): every module's figures for the last 1 to 12 months. */
class AnalyticsController extends ApiController
{
    public function __invoke(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $months = (int) $request->integer('months', 6);

        return $this->ok(['analytics' => $analytics->report(in_array($months, [1, 3, 6, 12], true) ? $months : 6)]);
    }
}
