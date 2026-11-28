<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Mobile\CollegeController as MobileCollegeController;
use App\Enums\Role;
use App\Http\Requests\StoreCollegeRequest;
use App\Http\Resources\CollegeResource;
use App\Services\CollegeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\College;

/** Same behaviour as mobile; access is gated by the web route group (Super Admin). */
class CollegeController extends MobileCollegeController {

    public function __construct(protected readonly CollegeService $colleges) {}

    public function index(): JsonResponse
    {
        return $this->ok(['colleges' => CollegeResource::collection($this->colleges->list(request()->user()))]);
    }

    /** One institute, same shape as the list; readable by every signed-in role, like the list itself. An institution reads only its own. */
    public function show(College $college): JsonResponse {
        abort_if(request()->user()->role === Role::Institution && $college->id !== request()->user()->college_id, 403, 'You can only view your own institute.');
        return $this->ok(['institute' => new CollegeResource($college)]);
    }

    public function update(StoreCollegeRequest $request, College $college): JsonResponse {
        // print_r($request->all());
        $college = $this->colleges->update($request->user(), $college, $request->validated());

        return $this->ok(['institute' => new CollegeResource($college)]);
    }

    public function delete(Request $request, College $college): JsonResponse {
        $this->colleges->delete($request->user(), $college);

        return $this->ok(['message' => "{$college->name} has been deleted."]);
    }
}
