<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\RedirectResponse;

class UserNotificationController extends Controller
{
    public function markRead(UserNotification $notification): RedirectResponse
    {
        abort_unless((int) $notification->user_id === (int) auth()->id(), 403);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return back()->with('status', 'Notification marked as read.');
    }
}
