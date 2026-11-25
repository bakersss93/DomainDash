@props(['checklist', 'showEnvironment' => false])
{{--
    Sandbox certification checklist: a step is ticked once a sandbox call with a TransactionID was recorded for it.
    Expects $checklist from App\Services\Monitoring\VocusJourneys::checklist().
--}}
@php
    $links = [
        'qualify' => route('admin.services.internet.qualify'),
        'order' => route('admin.services.internet.order'),
        'poll' => route('admin.services.internet'),
        'service' => route('admin.services.internet'),
    ];
    $hints = [
        'qualify' => 'Open Qualify, search an address or enter a LOC ID, and run the check.',
        'order' => 'Place an order for the qualified location. DomainDash sends the ServiceID and records the Create TransactionID.',
        'poll' => 'Open the new service and choose Check status (or wait for the scheduled sync) so the transaction is polled until Vocus finishes it.',
        'service' => 'Open the service page: DomainDash reads the live record (Get SERVICE).',
    ];
@endphp
<ol class="vc-check" aria-label="Sandbox journey checklist">
    @foreach ($checklist['steps'] as $i => $step)
        <li class="vc-check__item {{ $step['done'] ? 'is-done' : '' }}">
            <span class="vc-check__tick" aria-hidden="true">
                @if ($step['done'])<x-ui.icon name="check-circle" :size="20" />@else<span class="vc-check__num">{{ $i + 1 }}</span>@endif
            </span>
            <div class="vc-check__body">
                <p class="vc-check__label">{{ $step['label'] }}
                    <x-ui.badge :variant="$step['done'] ? 'success' : 'neutral'">{{ $step['done'] ? 'Recorded' : 'Not yet' }}</x-ui.badge>
                </p>
                <p class="vc-check__hint">{{ $step['done'] ? ($step['detail'] ?? '') : $hints[$step['key']] }}</p>
            </div>
            @unless ($step['done'])
                <a class="ui-btn ui-btn--secondary ui-btn--sm" href="{{ $links[$step['key']] }}">{{ $step['key'] === 'qualify' ? 'Qualify' : ($step['key'] === 'order' ? 'Order' : 'Services') }}</a>
            @endunless
        </li>
    @endforeach
</ol>
<p class="vc-check__summary">
    @if ($checklist['complete'])
        <x-ui.badge variant="success">Complete journey recorded</x-ui.badge> A sandbox journey with qualification, order and async follow-up exists.
    @else
        <x-ui.badge variant="warning">Journey incomplete</x-ui.badge> Vocus wants to see the journey from SQ to placing the order and following it up asynchronously.
    @endif
</p>
<style>
    .vc-check { list-style: none; margin: 0 0 .75rem; padding: 0; display: grid; gap: .5rem; }
    .vc-check__item { display: flex; gap: .75rem; align-items: center; padding: .6rem .75rem; border: 1px solid var(--dd-border); border-radius: var(--dd-radius); background: var(--dd-surface); }
    .vc-check__item.is-done { background: var(--dd-accent-soft); border-color: var(--dd-accent); }
    .vc-check__tick { flex: none; width: 1.6rem; height: 1.6rem; display: inline-flex; align-items: center; justify-content: center; color: var(--dd-success); }
    .vc-check__num { width: 1.4rem; height: 1.4rem; border-radius: var(--dd-radius-pill); border: 1px solid var(--dd-border-strong); color: var(--dd-text-soft); font-size: 12px; display: inline-flex; align-items: center; justify-content: center; }
    .vc-check__body { flex: 1; min-width: 0; }
    .vc-check__label { margin: 0; font-weight: 600; font-size: 14px; color: var(--dd-text); display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; }
    .vc-check__hint { margin: .15rem 0 0; font-size: 13px; color: var(--dd-text-soft); word-break: break-word; }
    .vc-check__summary { margin: 0; font-size: 13px; color: var(--dd-text-soft); display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
    @media (max-width: 520px) { .vc-check__item { flex-wrap: wrap; } }
</style>
