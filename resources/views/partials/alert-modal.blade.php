@php
    $alertMessage = $message ?? session('error') ?? session('status');
    $alertType = $type ?? (session()->has('error') ? 'error' : 'success');
    $alertTitle = $title ?? ($alertType === 'error' ? 'Action failed' : 'Success');
    $alertOpen = $open ?? filled($alertMessage);
    $alertListen = $listen ?? true;
@endphp

<div
    x-data="{
        open: @js((bool) $alertOpen),
        title: @js((string) $alertTitle),
        message: @js((string) $alertMessage),
        type: @js((string) $alertType),
        receiveAlert(event) {
            this.title = event.detail.title || 'Notification';
            this.message = event.detail.message || '';
            this.type = event.detail.type || 'success';
            this.open = true;
        }
    }"
    @if ($alertListen) x-on:app-alert.window="receiveAlert($event)" @endif
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[80] flex items-end justify-center bg-slate-950/60 p-0 backdrop-blur-sm sm:items-center sm:p-6"
    role="alertdialog"
    aria-modal="true"
    aria-label="Application notification"
>
    <button type="button" class="absolute inset-0 h-full w-full cursor-default" x-on:click="open = false" aria-label="Dismiss notification"></button>
    <div x-show="open" x-transition class="relative z-10 w-full rounded-t-3xl bg-white p-5 shadow-2xl sm:max-w-lg sm:rounded-3xl sm:p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p
                    class="mb-1 text-[11px] font-bold uppercase tracking-widest"
                    :class="{
                        'text-red-700': type === 'error',
                        'text-amber-700': type === 'warning',
                        'text-emerald-700': type === 'success',
                    }"
                    x-text="type === 'error' ? 'Error' : type === 'warning' ? 'Warning' : 'Notice'"
                ></p>
                <h2 class="text-lg font-semibold text-slate-900" x-text="title"></h2>
            </div>
            <button type="button" x-on:click="open = false" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close notification">×</button>
        </div>
        <p class="mt-3 text-sm leading-6 text-slate-600" x-text="message" aria-live="assertive"></p>
        <div class="mt-6 flex justify-end">
            <button type="button" x-on:click="open = false" class="mm-btn-primary">Close</button>
        </div>
    </div>
</div>
