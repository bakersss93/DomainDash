<button {{ $attributes->merge(['type' => 'button', 'class' => 'dd-btn dd-btn-secondary disabled:opacity-40']) }}>
    {{ $slot }}
</button>
