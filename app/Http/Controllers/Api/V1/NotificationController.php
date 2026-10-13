<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Support\ApiResponse;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contract §12 — in-app notifications (database channel). */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);

        $query = $request->user()->notifications()
            ->when($list->bool('unread') === true, fn ($q) => $q->whereNull('read_at'))
            ->latest();

        return ApiResponse::paginated($list->paginate($query, 10), NotificationResource::class);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return ApiResponse::success(new NotificationResource($notification));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::success(null, 'All notifications marked as read.');
    }
}
