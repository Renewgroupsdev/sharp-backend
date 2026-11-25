<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in user's own inbox. */
class NotificationController extends ApiController
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        return $this->ok([
            'unread' => $this->notifications->unreadCount($request->user()),
            'notifications' => NotificationResource::collection($this->notifications->inbox($request->user())),
        ]);
    }

    public function read(Request $request, Notification $notification): JsonResponse
    {
        $this->notifications->markRead($request->user(), $notification);

        return $this->ok(['unread' => $this->notifications->unreadCount($request->user())]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->notifications->markAllRead($request->user());

        return $this->ok(['unread' => 0]);
    }
}
