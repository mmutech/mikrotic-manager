<?php

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Choose a new password')] class extends Component
{
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function resetPassword(AuditLogger $audit): void
    {
        $this->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()],
        ]);

        $status = Password::reset(
            [
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function (User $user, string $password) use ($audit): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'password_changed_at' => now(),
                    'must_change_password' => false,
                ])->save();

                $user->setRememberToken(Str::random(60));
                $user->save();

                event(new PasswordReset($user));
                $audit->record('auth.password_reset', $user, metadata: ['email' => $user->email], actor: $user);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            session()->flash('status', 'Your password has been reset. Sign in with your new password.');
            $this->redirectRoute('login', navigate: true);

            return;
        }

        throw ValidationException::withMessages([
            'email' => match ($status) {
                Password::INVALID_TOKEN => 'This reset link is invalid or has expired. Request a new one.',
                Password::INVALID_USER => 'This reset link is no longer valid. Request a new one.',
                default => __($status),
            },
        ]);
    }

    public function render()
    {
        return $this->view()->title('Choose a new password');
    }
};
?>

<div>
    <div class="mb-7">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Account recovery</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Choose a new password</h1>
        <p class="mt-2 text-sm leading-6 text-slate-500">Use at least 12 characters with upper and lower case letters and a number.</p>
    </div>

    <form wire:submit="resetPassword" class="space-y-5">
        <input type="hidden" wire:model="token">

        <div>
            <label for="email" class="mm-label">Email address</label>
            <input
                id="email"
                type="email"
                wire:model="email"
                autocomplete="username"
                required
                class="mm-input"
            >
            @error('email') <p class="mm-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="mm-label">New password</label>
            <input
                id="password"
                type="password"
                wire:model="password"
                autocomplete="new-password"
                required
                class="mm-input"
                placeholder="At least 12 characters"
            >
            @error('password') <p class="mm-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="mm-label">Confirm new password</label>
            <input
                id="password_confirmation"
                type="password"
                wire:model="password_confirmation"
                autocomplete="new-password"
                required
                class="mm-input"
            >
        </div>

        <button type="submit" class="mm-btn-primary w-full" wire:loading.attr="disabled" wire:target="resetPassword">
            <span wire:loading.remove wire:target="resetPassword">Reset password</span>
            <span wire:loading wire:target="resetPassword">Resetting…</span>
        </button>
    </form>

    <div class="mt-6 text-center">
        <a href="{{ route('login') }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">Return to sign in</a>
    </div>
</div>
