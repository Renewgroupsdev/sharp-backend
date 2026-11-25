<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Services\SpecialistService;
use Illuminate\Http\JsonResponse;

class SpecialistController extends ApiController
{
    public function index(SpecialistService $specialists): JsonResponse
    {
        return $this->ok(['specialists' => $specialists->names()]);
    }
}
