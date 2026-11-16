<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return AppNotification::where('user_id', $this->user()->id)
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id')->paginate($this->perPage($request));
    }

    public function unreadCount()
    {
        return ['count' => AppNotification::where('user_id', $this->user()->id)->whereNull('read_at')->count()];
    }

    public function markRead(AppNotification $notification)
    {
        abort_unless($notification->user_id === $this->user()->id, 404);
        $notification->update(['read_at' => now()]);

        return $notification;
    }

    public function markAllRead()
    {
        AppNotification::where('user_id', $this->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'All caught up']);
    }
}
