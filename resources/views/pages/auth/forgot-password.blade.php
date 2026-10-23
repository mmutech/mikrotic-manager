<?php

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Forgot password')] class extends Component
{
    public string $email = '';

    public bool $sent = false;

    public function sendResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $key = 'password-email:'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'email' => 'Too many reset requests. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        RateLimiter::hit($key, 300);

        $status = Password::sendResetLink(['email' => $this->email]);

        /*
         * Only a throttled broker is reported back. Unknown addresses produce
         * the same neutral confirmation to avoid account enumeration.
         */
        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'email' => 'A reset link was requested recently. Check your inbox or try again shortly.',
            ]);
        }

        $this->sent = true;
    }

    public function render()
    {
        return $this->view()->title('Forgot password');
    }
};
?>

<div>
    <div class="mb-7">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Account recovery</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Reset your password</h1>
        <p class="mt-2 text-sm leading-6 text-slate-500">Enter the email address attached to your staff account. We will send a time-limited reset link.</p>
    </div>

    @if ($sent)
        <div class="rounded-xl border border-emerald-200 bg-success-50 px-4 py-3 text-sm leading-6 text-emerald-800" role="status">
            If an active account exists for <strong>{{ $email }}</strong>, a reset link has been sent. Check your inbox and spam folder.
        </div>

        <a href="{{ route('login') }}" class="mm-btn-secondary mt-6 w-full">Back to sign in</a>
    @else
        <form wire:submit="sendResetLink" class="space-y-5">
            <div>
                <label for="email" class="mm-label">Email address</label>
                <input
                    id="email"
                    type="email"
                    wire:model="email"
                    autocomplete="username"
                    autofocus
                    required
                    class="mm-input"
                    placeholder="you@example.com"
                >
                @error('email') <p class="mm-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="mm-btn-primary w-full" wire:loading.attr="disabled" wire:target="sendResetLink">
                <span wire:loading.remove wire:target="sendResetLink">Send reset link</span>
                <span wire:loading wire:target="sendResetLink">Sending…</span>
            </button>
        </form>

        <div class="mt-6 text-center">
            <a href="{{ route('login') }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">Return to sign in</a>
        </div>
    @endif
</div>
