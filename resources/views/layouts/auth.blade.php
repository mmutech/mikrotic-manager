<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-navy-950">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-full font-sans text-slate-900 antialiased">
        <div class="relative flex min-h-screen flex-col overflow-hidden bg-navy-950">
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-brand-600/20 blur-3xl"></div>
                <div class="absolute -bottom-40 right-0 h-[28rem] w-[28rem] rounded-full bg-emerald-500/10 blur-3xl"></div>
                <div class="absolute inset-0 opacity-[0.06]" style="background-image: linear-gradient(to right, #fff 1px, transparent 1px), linear-gradient(to bottom, #fff 1px, transparent 1px); background-size: 48px 48px;"></div>
            </div>

            <div class="relative mx-auto flex w-full max-w-6xl flex-1 flex-col justify-center px-4 py-10 sm:px-6 lg:grid lg:grid-cols-2 lg:gap-16 lg:px-8">
                <section class="hidden flex-col justify-center lg:flex">
                    <a href="{{ url('/') }}" class="inline-flex items-center gap-3">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-600 text-lg font-black text-white shadow-xl shadow-brand-600/30">MMU</span>
                        <span>
                            <span class="block text-xl font-bold tracking-tight text-white">{{ config('app.name') }}</span>
                            <span class="block text-xs font-medium uppercase tracking-[0.2em] text-slate-400">{{ config('mmutech.brand.product') }}</span>
                        </span>
                    </a>

                    <h1 class="mt-12 max-w-md text-4xl font-bold leading-tight tracking-tight text-white">
                        Operate every router from one accountable control plane.
                    </h1>
                    <p class="mt-5 max-w-md text-base leading-7 text-slate-400">
                        Register RouterOS 7 devices, verify read-only connectivity, and keep a redacted audit trail of every administrative action.
                    </p>

                    <dl class="mt-10 grid max-w-md grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Transport</dt>
                            <dd class="mt-1 text-sm font-semibold text-white">HTTPS only</dd>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Credentials</dt>
                            <dd class="mt-1 text-sm font-semibold text-white">Encrypted at rest</dd>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Access</dt>
                            <dd class="mt-1 text-sm font-semibold text-white">Role scoped</dd>
                        </div>
                    </dl>
                </section>

                <section class="w-full lg:max-w-md lg:justify-self-end">
                    <div class="mb-6 flex items-center gap-3 lg:hidden">
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-600 text-base font-black text-white">MM</span>
                        <span class="text-lg font-bold text-white">{{ config('app.name') }}</span>
                    </div>

                    <div class="rounded-3xl border border-white/10 bg-white p-6 shadow-2xl shadow-black/30 sm:p-8">
                        {{ $slot }}
                    </div>

                    <p class="mt-5 text-center text-xs text-slate-500">
                        Protected system. Access is logged and monitored.
                    </p>
                </section>
            </div>
        </div>
    </body>
</html>
