<x-ui.card title="Plans" description="Plan catalogue, speed tiers, account plan mapping and the HaloPSA item each plan bills as.">
    <p class="dd-set-note">{{ \App\Models\VocusPlan::count() }} plan(s) defined. Changes in the plan dialog save immediately.</p>
    <x-slot name="footer">
        <div class="dd-set-actions"><button type="button" class="ui-btn ui-btn--primary" data-modal-open="vocus-plans-modal">Manage plans</button></div>
    </x-slot>
</x-ui.card>
