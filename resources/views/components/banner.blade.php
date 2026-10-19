@props(['style' => session('flash.bannerStyle', 'success'), 'message' => session('flash.banner')])

<div x-data="{{ json_encode(['show' => true, 'style' => $style, 'message' => $message]) }}"
    :class="{ 'ui-alert--success': style == 'success', 'ui-alert--danger': style == 'danger', 'ui-alert--warning': style == 'warning', 'ui-alert--info': style != 'success' && style != 'danger' && style != 'warning'}"
    class="ui-alert"
    style="display: none; margin-bottom: 0; border-radius: 0; border-width: 0 0 1px 0;"
    role="status"
    x-show="show && message"
    x-on:banner-message.window="
        style = event.detail.style;
        message = event.detail.message;
        show = true;
    ">
    <div class="max-w-screen-xl mx-auto w-full flex items-center justify-between gap-3">
        <p class="font-medium text-sm truncate" x-text="message"></p>

        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ui-btn--icon" aria-label="Dismiss" x-on:click="show = false">
            <x-ui.icon name="x" :size="16" />
        </button>
    </div>
</div>
