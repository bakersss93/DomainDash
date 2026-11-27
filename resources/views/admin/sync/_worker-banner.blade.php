@php
    $__worker = app(\App\Services\Sync\QueueHealth::class)->status();
@endphp
{{-- Slim hint where a sync action is blocked without a queue worker. The full alert lives in the notification
     centre; DDSync (sync/_live) keeps every [data-dd-worker-banner] visible only while no worker is detected. --}}
<p class="dd-worker-hint" role="status" data-dd-worker-banner style="{{ $__worker['stale'] ? '' : 'display:none;' }}">
    <x-ui.icon name="alert" :size="16" />
    <span>Sync needs a queue worker. <button type="button" data-notif-open>See notifications</button></span>
</p>
