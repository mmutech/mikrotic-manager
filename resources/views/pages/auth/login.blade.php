<?php

use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Sign in')] class extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(AuditLogger $audit): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:'.Str::lower(Str::transliterate($this->email)).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key, 60);
            $this->password = '';

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($key);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isActive()) {
            Auth::guard('web')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'This account is not active. Contact an administrator.',
            ]);
        }

        request()->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        $audit->record('auth.login', $user, metadata: ['email' => $user->email], actor: $user);

        if ($user->must_change_password) {
            $this->redirectRoute('account.password', navigate: true);

            return;
        }

        $this->redirectIntended(route('dashboard'), navigate: true);
    }

    public function render()
    {
        return $this->view()->title('Sign in');
    }
};
?>

<div>
    <div class="mb-7">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Welcome back</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Sign in to {{ config('app.name') }}</h1>
        <p class="mt-2 text-sm leading-6 text-slate-500">Use your administrator or staff account to continue.</p>
    </div>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-success-50 px-3.5 py-3 text-sm text-emerald-800" role="status">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="login" class="space-y-5">
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

        <div>
            <div class="flex items-center justify-between">
                <label for="password" class="mm-label">Password</label>
                <a href="{{ route('password.request') }}" class="text-xs font-semibold text-brand-600 hover:text-brand-700">Forgot password?</a>
            </div>
            <input
                id="password"
                type="password"
                wire:model="password"
                autocomplete="current-password"
                required
                class="mm-input"
                placeholder="Enter your password"
            >
            @error('password') <p class="mm-error">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2.5 text-sm text-slate-600">
            <input type="checkbox" wire:model="remember" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600">
            <span>Keep me signed in on this device</span>
        </label>

        <button type="submit" class="mm-btn-primary w-full" wire:loading.attr="disabled" wire:target="login">
            <svg wire:loading wire:target="login" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"></path>
            </svg>
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>

    <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-xs leading-5 text-slate-500">
        Access is limited to active accounts. Repeated failed attempts trigger a temporary lockout.
    </div>
</div>
