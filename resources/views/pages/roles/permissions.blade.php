<?php

use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

new #[Layout('layouts::app')] #[Title('Role permissions')] class extends Component
{
    public Role $role;
    public array $selected = [];

    public function mount(Role $role): void
    {
        $this->authorize('assignRoles', User::class);
        $this->role = $role;
        $this->selected = $role->permissions->pluck('name')->all();
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('assignRoles', User::class);
        $before = $this->role->permissions->pluck('name')->all();
        $this->role->syncPermissions($this->selected);
        $audit->record('role.permissions_updated', $this->role, before: ['permissions' => $before], after: ['permissions' => $this->role->fresh()->permissions->pluck('name')->all()], actor: auth()->user());
        session()->flash('status', 'Permissions updated.');
        $this->redirectRoute('roles.show', ['role' => $this->role->id], navigate: true);
    }

    public function render()
    {
        $all = Permission::all()->pluck('name')->sort()->values()->all();
        return $this->view([
            'permissions' => $all,
        ])->title('Role permissions');
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <a href="{{ route('roles.show', $role) }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to role</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Manage Permissions - {{ $role->name }}</h1>
    </div>
    <form wire:submit="save" class="mm-card p-6 space-y-4">
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach ($permissions as $perm)
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="selected" value="{{ $perm }}" class="rounded border-slate-300">
                    <span class="text-sm text-slate-700">{{ $perm }}</span>
                </label>
            @endforeach
        </div>
        <div class="flex justify-end gap-3">
            <a href="{{ route('roles.show', $role) }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary">Save permissions</button>
        </div>
    </form>
</div>
