<?php

use App\Models\Router;
use App\Models\User;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Assign routers')] class extends Component
{
    public User $user;
    public array $selected = [];

    public function mount(User $user): void
    {
        $this->authorize('assignRouter', User::class);
        $this->user = $user;
        $this->selected = $user->routers->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('assignRouter', User::class);
        $before = $this->user->routers->pluck('name')->all();

        $this->user->routers()->sync($this->selected);
        
        $audit->record('user.routers_updated', $this->user, before: ['routers' => $before], after: ['routers' => $this->user->fresh()->routers->pluck('name')->all()], actor: auth()->user());
        session()->flash('status', 'Router access updated.');
        $this->redirectRoute('users.show', ['user' => $this->user->id], navigate: true);
    }

    public function render()
    {
        return $this->view([
            'routers' => Router::query()->orderBy('name')->get(),
        ])->title('Assign routers');
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <a href="{{ route('users.show', $user) }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to user</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Assign Router Access - {{ $user->name }}</h1>
    </div>
    <form wire:submit="save" class="mm-card space-y-4 p-6">
        @foreach ($routers as $router)
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model="selected" value="{{ $router->id }}" class="rounded border-slate-300">
                <span class="text-sm text-slate-700">{{ $router->name }} ({{ $router->host }})</span>
            </label>
        @endforeach
        <div class="flex justify-end gap-3">
            <a href="{{ route('users.show', $user) }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary">Save access</button>
        </div>
    </form>
</div>
