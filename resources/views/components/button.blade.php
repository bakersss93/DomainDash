<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-accent disabled:opacity-50']) }}>
    {{ $slot }}
</button>
