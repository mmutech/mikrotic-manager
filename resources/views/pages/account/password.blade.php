<?php

use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Security')] class extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        if (auth()->user()->must_change_password) {
            session()->now('error', 'Your password was set by an administrator and must be changed before continuing.');
        }
    }

    public function updatePassword(AuditLogger $audit): void
    {
        $this->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', PasswordRule::min(12)->mixedCase()->numbers()],
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $user->forceFill([
            'password' => Hash::make($this->password),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->save();

        Auth::logoutOtherDevices($this->current_password);

        $audit->record('auth.password_changed', $user, metadata: ['email' => $user->email], actor: $user);

        $this->reset('current_password', 'password', 'password_confirmation');
        session()->flash('status', 'Your password has been changed. Other sessions have been signed out.');
    }

    public function render()
    {
        return $this->view([
            'user' => auth()->user(),
        ])->title('Security');
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Account</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Security settings</h1>
        <p class="mt-1 text-sm text-slate-500">Manage the password for your own account. Router credentials are managed separately and can never be read back.</p>
    </div>

    <div class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
            <h2 class="text-base font-semibold text-slate-900">Account overview</h2>
        </div>
        <dl class="grid gap-4 px-5 py-5 sm:grid-cols-2 sm:px-6">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Name</dt>
                <dd class="mt-1 text-sm font-medium text-slate-900">{{ $user->name }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Email</dt>
                <dd class="mt-1 text-sm font-medium text-slate-900">{{ $user->email }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Roles</dt>
                <dd class="mt-1 text-sm font-medium text-slate-900">{{ implode(', ', $user->roleLabels()) ?: 'None' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Last sign-in</dt>
                <dd class="mt-1 text-sm font-medium text-slate-900">{{ $user->last_login_at?->diffForHumans() ?? 'This session' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Password changed</dt>
                <dd class="mt-1 text-sm font-medium text-slate-900">{{ $user->password_changed_at?->format('j M Y, H:i') ?? 'Not recorded' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Account status</dt>
                <dd class="mt-1 text-sm font-medium text-slate-900">{{ $user->status->label() }}</dd>
            </div>
        </dl>
    </div>

    <div class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
            <h2 class="text-base font-semibold text-slate-900">Change password</h2>
            <p class="mt-1 text-sm text-slate-500">Changing your password signs out your other active sessions.</p>
        </div>

        <form wire:submit="updatePassword" class="space-y-5 px-5 py-5 sm:px-6">
            <div>
                <label for="current_password" class="mm-label">Current password</label>
                <input id="current_password" type="password" wire:model="current_password" autocomplete="current-password" required class="mm-input">
                @error('current_password') <p class="mm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password" class="mm-label">New password</label>
                <input id="password" type="password" wire:model="password" autocomplete="new-password" required class="mm-input" placeholder="At least 12 characters">
                <p class="mm-help">Use at least 12 characters with upper and lower case letters and a number.</p>
                @error('password') <p class="mm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password_confirmation" class="mm-label">Confirm new password</label>
                <input id="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password" required class="mm-input">
            </div>

            <div class="flex items-center justify-end">
                <button type="submit" class="mm-btn-primary" wire:loading.attr="disabled" wire:target="updatePassword">
                    <span wire:loading.remove wire:target="updatePassword">Update password</span>
                    <span wire:loading wire:target="updatePassword">Updating…</span>
                </button>
            </div>
        </form>
    </div>
</div>
