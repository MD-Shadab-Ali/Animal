<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * What has happened to a buyer's orders, stays and money.
 *
 * Every row is written by Laravel's database channel from the same notification
 * classes that send the email, so the bell and the inbox can never tell
 * different stories about the same event.
 *
 * Scoped through the relation rather than by a where clause on notifiable_id:
 * the relation carries the morph type as well, so an id that happens to match a
 * row of another kind cannot hand somebody else's news over.
 */
class NotificationController extends Controller
{
    /** How many the bell shows before it gives up counting. */
    private const BADGE_CAP = 99;

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $notifications = $user->notifications()->latest()->paginate(20);

        $unread = $user->unreadNotifications()->count();

        return NotificationResource::collection($notifications)->additional([
            'meta' => [
                'unread' => $unread,
                // Pre-formatted, so every place that draws the badge agrees on
                // what "too many to count" looks like.
                'unread_badge' => $unread > self::BADGE_CAP ? self::BADGE_CAP.'+' : (string) $unread,
            ],
        ]);
    }

    /**
     * Read one.
     *
     * Idempotent: opening the same notification twice is the most ordinary
     * thing a person can do, and the second time must not be an error.
     */
    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();

        $notification->markAsRead();

        return response()->json([
            'data' => new NotificationResource($notification->fresh()),
            'meta' => ['unread' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    /** Clear the badge in one go, which is the only thing most people want. */
    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'message' => 'All caught up.',
            'meta' => ['unread' => 0],
        ]);
    }
}
