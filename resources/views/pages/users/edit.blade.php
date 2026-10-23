<?php

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Edit user')] class extends Component
{
    public User $user;
    public string $name = '';
    public string $email = '';
    public string $status = 'active';

    public function mount(User $user): void
    {
        $this->authorize('update', $user);
        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->status = $user->status->value;
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('update', $this->user);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user->id)],
            'status' => ['required', 'in:active,suspended,invited,disabled'],
        ]);
        $before = $this->user->only(['name', 'email', 'status']);
        $this->user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'status' => UserStatus::from($validated['status']),
        ]);
        $audit->record('user.updated', $this->user, before: $before, after: $this->user->fresh()->only(['name', 'email', 'status']), actor: auth()->user());
        session()->flash('status', 'User updated.');
        $this->redirectRoute('users.show', ['user' => $this->user->id], navigate: true);
    }

    public function render()
    {
        return $this->view()->title('Edit user');
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <a href="{{ route('users.show', $user) }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to user</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Edit User</h1>
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
            <a href="{{ route('users.show', $user) }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary">Save changes</button>
        </div>
    </form>
</div>
