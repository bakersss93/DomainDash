<button {{ $attributes->merge(['type' => 'submit', 'class' => 'dd-btn dd-btn-primary disabled:opacity-50']) }}>
    {{ $slot }}
</button>
