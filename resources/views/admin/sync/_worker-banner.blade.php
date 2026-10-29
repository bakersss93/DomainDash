@php
    $__worker = app(\App\Services\Sync\QueueHealth::class)->status();
@endphp
{{-- Shown when no queue worker is detected; DDSync (sync/_live) keeps every [data-dd-worker-banner] current. --}}
<x-ui.alert variant="warning" data-dd-worker-banner style="margin-bottom:1rem;{{ $__worker['stale'] ? '' : 'display:none;' }}">
    <span data-dd-worker-msg>{{ $__worker['message'] }}</span>
</x-ui.alert>
