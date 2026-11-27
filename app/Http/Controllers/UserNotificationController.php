<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Services\Notifications\NotificationSummary;
use App\Services\Notifications\SystemAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserNotificationController extends Controller
{
    /** Bell data. Everyone gets their own notifications; alerts and in-progress tasks are for administrators only. */
    public function summary(Request $request, NotificationSummary $summary): JsonResponse
    {
        return response()->json($summary->for($request->user()));
    }

    public function markRead(Request $request, UserNotification $notification, NotificationSummary $summary): RedirectResponse|JsonResponse
    {
        abort_unless((int) $notification->user_id === (int) auth()->id(), 403);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }
        NotificationSummary::forget($request->user());

        if ($request->expectsJson()) {
            return response()->json($summary->for($request->user()));
        }

        return back()->with('status', 'Notification marked as read.');
    }

    public function markAllRead(Request $request, NotificationSummary $summary): RedirectResponse|JsonResponse
    {
        UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);
        NotificationSummary::forget($request->user());

        return $request->expectsJson() ? response()->json($summary->for($request->user())) : back();
    }

    /** Dismiss/snooze an alert for the current administrator; it returns after the period if still true. */
    public function snoozeAlert(Request $request, SystemAlerts $alerts, NotificationSummary $summary): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'hours' => ['nullable', 'integer', 'in:' . implode(',', SystemAlerts::SNOOZE_HOURS)],
        ]);

        $alerts->snooze($request->user(), $data['key'], isset($data['hours']) ? (int) $data['hours'] : null);
        NotificationSummary::forget($request->user());

        return response()->json($summary->for($request->user()));
    }
}
