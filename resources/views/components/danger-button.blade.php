<button {{ $attributes->merge(['type' => 'button', 'class' => 'dd-btn dd-btn-danger']) }}>
    {{ $slot }}
</button>
