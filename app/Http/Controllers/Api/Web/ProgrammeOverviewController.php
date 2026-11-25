<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Services\ProgrammeOverviewService;
use Illuminate\Http\JsonResponse;

/** The admin dashboard's programme numbers (staff only). */
class ProgrammeOverviewController extends ApiController
{
    public function __invoke(ProgrammeOverviewService $overview): JsonResponse
    {
        return $this->ok(['overview' => $overview->overview()]);
    }
}
