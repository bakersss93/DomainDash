{{-- Buttons shown inside Settings -> Vocus Wholesale (the modals live outside the settings form: partials/vocus-modals) --}}
<div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--dd-border);">
    <h4 style="font-size:14px;font-weight:600;margin:0 0 4px;color:var(--text,#111827);">Ordering options</h4>
    <p style="font-size:12px;color:var(--dd-text-soft);margin:0 0 10px;">These save immediately and drive the NBN order and transfer forms.</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-modal-open="vocus-realms-modal">PPPoE realms</button>
        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-modal-open="vocus-scopes-modal">Service scopes</button>
        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-modal-open="vocus-plans-modal">Plans</button>
    </div>
</div>
