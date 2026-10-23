<?php

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('User details')] class extends Component
{
    public User $user;

    public function mount(User $user): void
    {
        $this->authorize('view', $user);
        $this->user = $user->load(['roles', 'routers']);
    }

    public function render()
    {
        $auth = auth()->user();
        return $this->view([
            'canUpdate' => $auth->can('update', $this->user),
            'canAssignRoles' => $auth->can('assignRoles', User::class),
            'canAssignRouter' => $auth->can('assignRouter', User::class),
        ])->title($this->user->name);
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('users.index') }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to users</a>
            <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">{{ $user->name }}</h1>
            <p class="text-sm text-slate-500">{{ $user->email }}</p>
        </div>
        @if ($canUpdate)
            <a href="{{ route('users.edit', $user) }}" class="mm-btn-secondary">Edit user</a>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="mm-card p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">Details</h2>
            <dl class="space-y-3">
                <div class="flex justify-between"><dt class="text-sm text-slate-500">Status</dt><dd class="text-sm text-slate-900">{{ $user->status->value }}</dd></div>
                <div class="flex justify-between"><dt class="text-sm text-slate-500">Roles</dt><dd class="text-sm text-slate-900">{{ $user->roles->pluck('name')->implode(', ') ?: 'None' }}</dd></div>
                <div class="flex justify-between"><dt class="text-sm text-slate-500">Created</dt><dd class="text-sm text-slate-900">{{ $user->created_at->format('Y-m-d H:i') }}</dd></div>
            </dl>
        </div>
        <div class="mm-card p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-slate-900">Router Access</h2>
                @if ($canAssignRouter)
                    <a href="{{ route('users.routers', $user) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">Manage</a>
                @endif
            </div>
            <ul class="space-y-2">
                @forelse ($user->routers as $router)
                    <li class="text-sm text-slate-700">{{ $router->name }} ({{ $router->host }})</li>
                @empty
                    <li class="text-sm text-slate-500">No routers assigned</li>
                @endforelse
            </ul>
        </div>
    </div>

    @if ($canAssignRoles)
        <div class="mm-card p-4">
            <a href="{{ route('users.roles', $user) }}" class="mm-btn-secondary">Manage Roles</a>
        </div>
    @endif
</div>
