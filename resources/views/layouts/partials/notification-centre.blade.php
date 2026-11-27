@php
    $__summary = app(\App\Services\Notifications\NotificationSummary::class)->for(auth()->user());
@endphp
{{-- Topbar notification centre. Markup is static; resources/js/notification-centre.js renders the lists (textContent only). --}}
<div data-notif data-url="{{ route('notifications.summary') }}" data-snooze-url="{{ route('admin.notifications.alerts.snooze') }}"
     data-read-all-url="{{ route('notifications.read-all') }}" data-initial="{{ json_encode($__summary) }}">
    <button type="button" class="topbar-icon-btn notif-trigger" data-notif-bell id="notif-bell" aria-haspopup="dialog"
            aria-expanded="false" aria-controls="notif-panel"
            aria-label="Notifications{{ $__summary['count'] ? ': '.$__summary['count'].' need review' : '' }}">
        <x-ui.icon name="bell" />
        <span class="notif-badge{{ $__summary['severity'] ? ' notif-badge--'.$__summary['severity'] : '' }}" data-notif-badge @if (! $__summary['count']) hidden @endif aria-hidden="true">{{ $__summary['count'] > 99 ? '99+' : $__summary['count'] }}</span>
        <span class="notif-spin" data-notif-spin @if (! $__summary['in_progress_count']) hidden @endif aria-hidden="true"></span>
    </button>
    <div class="notif-overlay" data-notif-overlay hidden></div>
    <aside class="notif-panel" id="notif-panel" data-notif-panel role="dialog" aria-modal="true" aria-labelledby="notif-title" tabindex="-1" hidden>
        <div class="notif-panel__head">
            <h2 id="notif-title">Notifications</h2>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-notif-markall hidden>Mark all read</button>
            <button type="button" class="topbar-icon-btn" data-notif-close aria-label="Close notifications"><x-ui.icon name="x" /></button>
        </div>
        <div class="notif-panel__body" data-notif-body></div>
    </aside>
    <div class="sr-only" role="status" aria-live="polite" data-notif-live></div>
</div>
