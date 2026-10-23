<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new #[Layout('layouts::app')] #[Title('Create role')] class extends Component
{
    public string $name = '';

    public function mount(): void
    {
        $this->authorize('assignRoles', App\Models\User::class);
    }

    public function save(): void
    {
        $this->authorize('assignRoles', App\Models\User::class);
        $this->validate(['name' => ['required', 'string', 'max:50', 'unique:roles,name']]);
        Role::create(['name' => $this->name, 'guard_name' => 'web']);
        session()->flash('status', 'Role created.');
        $this->redirectRoute('roles.index', navigate: true);
    }

    public function render()
    {
        return $this->view()->title('Create role');
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <a href="{{ route('roles.index') }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to roles</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Create Role</h1>
    </div>
    <form wire:submit="save" class="mm-card space-y-5 p-6">
        <div>
            <label class="mm-label">Role name *</label>
            <input type="text" wire:model="name" class="mm-input">
            @error('name') <p class="mm-error">{{ $message }}</p> @enderror
        </div>
        <div class="flex justify-end gap-3">
            <a href="{{ route('roles.index') }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary">Create role</button>
        </div>
    </form>
</div>
