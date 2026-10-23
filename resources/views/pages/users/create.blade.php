<?php

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Create user')] class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $status = 'active';

    public function mount(): void
    {
        $this->authorize('create', User::class);
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('create', User::class);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'status' => ['required', 'in:active,suspended,invited,disabled'],
        ]);
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'status' => UserStatus::from($validated['status']),
        ]);
        $audit->record('user.created', $user, after: ['name' => $user->name, 'email' => $user->email], actor: auth()->user());
        session()->flash('status', 'User created.');
        $this->redirectRoute('users.show', ['user' => $user->id], navigate: true);
    }

    public function render()
    {
        return $this->view()->title('Create user');
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <a href="{{ route('users.index') }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to users</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Create User</h1>
    </div>
    <form wire:submit="save" class="mm-card space-y-5 p-6">
        <div>
            <label class="mm-label">Name *</label>
            <input type="text" wire:model="name" class="mm-input">
            @error('name') <p class="mm-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="mm-label">Email *</label>
            <input type="email" wire:model="email" class="mm-input">
            @error('email') <p class="mm-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="mm-label">Password *</label>
            <input type="password" wire:model="password" class="mm-input">
            @error('password') <p class="mm-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="mm-label">Status *</label>
            <select wire:model="status" class="mm-input">
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="invited">Invited</option>
                <option value="disabled">Disabled</option>
            </select>
            @error('status') <p class="mm-error">{{ $message }}</p> @enderror
        </div>
        <div class="flex justify-end gap-3">
            <a href="{{ route('users.index') }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary">Create user</button>
        </div>
    </form>
</div>
