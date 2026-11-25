<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\SendNotificationRequest;
use App\Http\Requests\UpdateNotificationRuleRequest;
use App\Http\Resources\NotificationRuleResource;
use App\Services\NotificationRuleService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Notification Management (staff): the rules behind every automatic notification, and messages written by hand. */
class NotificationRuleController extends ApiController
{
    public function __construct(private readonly NotificationRuleService $rules, private readonly NotificationService $notifications) {}

    public function index(): JsonResponse
    {
        return $this->ok(['rules' => NotificationRuleResource::collection($this->rules->all())]);
    }

    public function update(UpdateNotificationRuleRequest $request, string $event): JsonResponse
    {
        return $this->ok(['rule' => new NotificationRuleResource($this->rules->update($request->user(), $this->rules->find($event), $request->validated()))]);
    }

    public function reset(Request $request, string $event): JsonResponse
    {
        return $this->ok(['rule' => new NotificationRuleResource($this->rules->reset($request->user(), $this->rules->find($event)))]);
    }

    public function send(SendNotificationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $sent = $this->notifications->compose($request->user(), $data['roles'] ?? [], $data['batch_id'] ?? null, $data['users'] ?? [], $data['title'], $data['body'] ?? null);

        return $this->ok(['sent' => $sent, 'message' => $sent === 1 ? 'Sent to 1 person.' : "Sent to {$sent} people."], 201);
    }
}
