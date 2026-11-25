<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Services\ActivityReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The dashboard's activity charts (staff): everything people did in the last 7, 14, 30 or 90 days. */
class ActivityReportController extends ApiController
{
    public function __construct(private readonly ActivityReportService $report) {}

    public function __invoke(Request $request): JsonResponse
    {
        $days = (int) $request->integer('days', 14);

        return $this->ok(['activity' => $this->report->report(in_array($days, [7, 14, 30, 90], true) ? $days : 14)]);
    }
}
