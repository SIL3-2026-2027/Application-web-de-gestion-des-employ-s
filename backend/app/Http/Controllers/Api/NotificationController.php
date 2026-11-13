<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** NOT-01 : cloche de l'application ; NOT-05 : préférences d'e-mail. */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['unread' => ['boolean']]);
        $user = $request->user();

        $notifications = $user->notifications()
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->latest()
            ->paginate(20);

        return [
            'unread_count' => $user->unreadNotifications()->count(),
            'data' => collect($notifications->items())->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                ...$n->data,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at->toIso8601String(),
            ]),
            'meta' => ['current_page' => $notifications->currentPage(), 'last_page' => $notifications->lastPage(), 'total' => $notifications->total()],
        ];
    }

    public function markRead(Request $request, string $id)
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return ['unread_count' => $request->user()->unreadNotifications()->count()];
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ['unread_count' => 0];
    }

    public function preferences(Request $request)
    {
        $user = $request->user();

        return collect(AppNotification::CATEGORIES)->map(fn (string $label, string $key) => [
            'key' => $key,
            'label' => $label,
            'email' => $user->wantsEmailFor($key),
        ])->values();
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'array'],
            'email.*' => ['boolean'],
        ]);

        $known = array_intersect_key($data['email'], AppNotification::CATEGORIES);
        $request->user()->update(['notification_preferences' => [
            ...($request->user()->notification_preferences ?? []),
            ...array_map('boolval', $known),
        ]]);

        return $this->preferences($request);
    }
}
