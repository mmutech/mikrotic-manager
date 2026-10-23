<?php

use App\Models\User;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Layout('layouts::app')] #[Title('Assign roles')] class extends Component
{
    public User $user;
    public array $selected = [];

    public function mount(User $user): void
    {
        $this->authorize('assignRoles', User::class);
        $this->user = $user;
        $this->selected = $user->roles->pluck('name')->all();
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('assignRoles', User::class);
        $before = $this->user->roles->pluck('name')->all();
        $this->user->syncRoles($this->selected);
        $audit->record('user.roles_updated', $this->user, before: ['roles' => $before], after: ['roles' => $this->user->fresh()->roles->pluck('name')->all()], actor: auth()->user());
        session()->flash('status', 'Roles updated.');
        $this->redirectRoute('users.show', ['user' => $this->user->id], navigate: true);
    }

    public function render()
    {
        return $this->view([
            'roles' => Role::all(),
        ])->title('Assign roles');
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <a href="{{ route('users.show', $user) }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to user</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Assign Roles - {{ $user->name }}</h1>
    </div>
    <form wire:submit="save" class="mm-card space-y-4 p-6">
        @foreach ($roles as $role)
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model="selected" value="{{ $role->name }}" class="rounded border-slate-300">
                <span class="text-sm text-slate-700">{{ $role->name }}</span>
            </label>
        @endforeach
        <div class="flex justify-end gap-3">
            <a href="{{ route('users.show', $user) }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary">Save roles</button>
        </div>
    </form>
</div>
