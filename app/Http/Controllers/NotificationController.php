<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {
    }

    // GET /api/notifications
    public function index(Request $request): JsonResponse
    {
        $notifications = $this->notificationService->list($request->user());
        return ApiResponse::success('Notifications retrieved.', $notifications);
    }

    // GET /api/notifications/recent
    public function recent(Request $request): JsonResponse
    {
        $notifications = $this->notificationService->recent($request->user());
        $unread = $this->notificationService->unreadCount($request->user());
        return ApiResponse::success('Recent notifications retrieved.', [
            'notifications' => $notifications,
            'unread_count' => $unread,
        ]);
    }

    // GET /api/notifications/unread-count
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->notificationService->unreadCount($request->user());
        return ApiResponse::success('Unread count retrieved.', ['count' => $count]);
    }

    // PUT /api/notifications/{id}/read
    public function markRead(Request $request, int $id): JsonResponse
    {
        $this->notificationService->markRead($request->user(), $id);
        return ApiResponse::success('Notification marked as read.');
    }

    // PUT /api/notifications/read-all
    public function markAllRead(Request $request): JsonResponse
    {
        $this->notificationService->markAllRead($request->user());
        return ApiResponse::success('All notifications marked as read.');
    }
}