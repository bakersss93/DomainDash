<button {{ $attributes->merge(['type' => 'button', 'class' => 'ui-btn ui-btn--danger']) }}>
    {{ $slot }}
</button>
