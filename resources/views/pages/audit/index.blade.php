<?php

use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Audit trail')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public ?int $selectedLogId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', AuditLog::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'action', 'from', 'to');
        $this->resetPage();
    }

    public function showDetails(int $logId): void
    {
        $this->authorize('viewAny', AuditLog::class);

        $this->selectedLogId = $logId;
    }

    public function closeDetails(): void
    {
        $this->selectedLogId = null;
    }

    public function render()
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = AuditLog::query()->with('actor')->latest('created_at');

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';

            $query->where(function ($query) use ($term): void {
                $query->where('action', 'like', $term)
                    ->orWhere('correlation_id', 'like', $term)
                    ->orWhere('ip_address', 'like', $term)
                    ->orWhereHasMorph('actor', [User::class], function ($query) use ($term): void {
                        $query->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term);
                    });
            });
        }

        if ($this->action !== '') {
            $query->where('action', $this->action);
        }

        if ($this->from !== '') {
            $query->whereDate('created_at', '>=', $this->from);
        }

        if ($this->to !== '') {
            $query->whereDate('created_at', '<=', $this->to);
        }

        $selectedLog = $this->selectedLogId !== null
            ? AuditLog::query()->with('actor')->find($this->selectedLogId)
            : null;

        return $this->view([
            'logs' => $query->paginate(20),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'hasFilters' => $this->search !== '' || $this->action !== '' || $this->from !== '' || $this->to !== '',
            'selectedLog' => $selectedLog,
        ])->title('Audit trail');
    }
};
?>

<div class="space-y-6">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Accountability</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Audit trail</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-500">
            Immutable application-level records for sensitive actions. Known secret fields are redacted before storage. Router API passwords are never included.
        </p>
    </div>

    <section class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 p-4 sm:p-5">
            <div class="grid gap-3 lg:grid-cols-12">
                <div class="lg:col-span-5">
                    <label for="audit-search" class="sr-only">Search audit records</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z" />
                            </svg>
                        </span>
                        <input id="audit-search" type="search" wire:model.live.debounce.300ms="search" class="mm-input pl-10" placeholder="Action, actor, correlation ID, IP…">
                    </div>
                </div>

                <div class="lg:col-span-3">
                    <label for="audit-action" class="sr-only">Filter by action</label>
                    <select id="audit-action" wire:model.live="action" class="mm-input">
                        <option value="">All actions</option>
                        @foreach ($actions as $option)
                            <option value="{{ $option }}">{{ str($option)->replace('.', ' · ')->title() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="lg:col-span-2">
                    <label for="audit-from" class="sr-only">From date</label>
                    <input id="audit-from" type="date" wire:model.live="from" class="mm-input" title="From date">
                </div>

                <div class="lg:col-span-2">
                    <label for="audit-to" class="sr-only">To date</label>
                    <input id="audit-to" type="date" wire:model.live="to" class="mm-input" title="To date">
                </div>
            </div>

            @if ($hasFilters)
                <div class="mt-3 flex items-center justify-between gap-3">
                    <p class="text-xs text-slate-500">Filters are combined. Dates are inclusive.</p>
                    <button type="button" wire:click="clearFilters" class="text-xs font-semibold text-brand-600 hover:text-brand-700">Clear filters</button>
                </div>
            @endif
        </div>

        @if ($logs->isEmpty())
            <div class="px-6 py-14 text-center">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2.25-13.5H6.75A2.25 2.25 0 0 0 4.5 4.75v14.5A2.25 2.25 0 0 0 6.75 21.5h10.5a2.25 2.25 0 0 0 2.25-2.25V8.25L14.25 3H6.75a2.25 2.25 0 0 0-2.25 2.25" />
                    </svg>
                </span>
                <h2 class="mt-3 text-sm font-semibold text-slate-900">{{ $hasFilters ? 'No audit records match these filters' : 'No audit records yet' }}</h2>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                    {{ $hasFilters ? 'Adjust the filters to widen the search.' : 'Administrative actions will appear here as they occur.' }}
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="mm-th">Time</th>
                            <th scope="col" class="mm-th">Action</th>
                            <th scope="col" class="mm-th">Actor</th>
                            <th scope="col" class="mm-th">Target</th>
                            <th scope="col" class="mm-th">Source</th>
                            <th scope="col" class="mm-th text-right">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($logs as $log)
                            <tr wire:key="audit-{{ $log->id }}" class="hover:bg-slate-50/70">
                                <td class="mm-td whitespace-nowrap">
                                    <span class="text-xs font-medium text-slate-700">{{ $log->created_at?->format('j M Y, H:i:s') }}</span>
                                    <span class="mt-0.5 block text-xs text-slate-400">{{ $log->created_at?->diffForHumans() }}</span>
                                </td>
                                <td class="mm-td">
                                    <span class="font-mono text-xs font-medium text-slate-800">{{ $log->action }}</span>
                                </td>
                                <td class="mm-td">
                                    <span class="text-sm text-slate-900">{{ $log->actor?->name ?? 'System' }}</span>
                                    <span class="mt-0.5 block text-xs text-slate-500">{{ $log->actor?->email ?? 'No authenticated actor' }}</span>
                                </td>
                                <td class="mm-td">
                                    @if ($log->auditable_type)
                                        <span class="text-xs font-medium text-slate-700">{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</span>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="mm-td">
                                    <span class="font-mono text-xs text-slate-600">{{ $log->ip_address ?? '—' }}</span>
                                    @if ($log->correlation_id)
                                        <span class="mt-0.5 block max-w-[12rem] truncate font-mono text-[11px] text-slate-400" title="{{ $log->correlation_id }}">{{ $log->correlation_id }}</span>
                                    @endif
                                </td>
                                <td class="mm-td text-right">
                                    <button type="button" wire:click="showDetails({{ $log->id }})" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">View</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($logs->hasPages())
                <div class="border-t border-slate-200 px-4 py-3 sm:px-5">
                    {{ $logs->links() }}
                </div>
            @endif
        @endif
    </section>

    @if ($selectedLog)
        <div class="fixed inset-0 z-[60] flex items-end justify-center bg-slate-950/60 p-0 backdrop-blur-sm sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-labelledby="audit-detail-title">
            <button type="button" class="absolute inset-0 h-full w-full cursor-default" wire:click="closeDetails" aria-label="Close details"></button>

            <div class="relative z-10 max-h-[90vh] w-full overflow-y-auto rounded-t-3xl bg-white p-5 shadow-2xl sm:max-w-2xl sm:rounded-3xl sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Audit record</p>
                        <h2 id="audit-detail-title" class="mt-1 font-mono text-lg font-semibold text-slate-900">{{ $selectedLog->action }}</h2>
                        <p class="mt-1 text-xs text-slate-500">{{ $selectedLog->created_at?->format('l, j F Y \a\t H:i:s') }}</p>
                    </div>
                    <button type="button" wire:click="closeDetails" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close details">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                        </svg>
                    </button>
                </div>

                <dl class="mt-5 grid gap-4 rounded-2xl bg-slate-50 p-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Actor</dt>
                        <dd class="mt-1 text-slate-900">{{ $selectedLog->actor?->name ?? 'System' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Actor email</dt>
                        <dd class="mt-1 break-all text-slate-900">{{ $selectedLog->actor?->email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Target</dt>
                        <dd class="mt-1 text-slate-900">{{ $selectedLog->auditable_type ? class_basename($selectedLog->auditable_type).' #'.$selectedLog->auditable_id : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Correlation ID</dt>
                        <dd class="mt-1 break-all font-mono text-xs text-slate-900">{{ $selectedLog->correlation_id ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Source IP</dt>
                        <dd class="mt-1 font-mono text-xs text-slate-900">{{ $selectedLog->ip_address ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">User agent</dt>
                        <dd class="mt-1 break-words text-xs text-slate-700">{{ $selectedLog->user_agent ?? '—' }}</dd>
                    </div>
                </dl>

                @foreach (['metadata' => 'Metadata', 'before' => 'Before', 'after' => 'After'] as $field => $label)
                    @if (filled($selectedLog->{$field}))
                        <div class="mt-4">
                            <h3 class="text-sm font-semibold text-slate-900">{{ $label }}</h3>
                            <pre class="mt-2 max-h-64 overflow-auto rounded-xl bg-slate-950 p-3 text-xs leading-5 text-slate-100">{{ json_encode($selectedLog->{$field}, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    @endif
                @endforeach

                <p class="mt-4 text-xs leading-5 text-slate-500">Known secret-bearing keys are redacted at write time. Values shown here are the stored audit copy, not live credentials.</p>
            </div>
        </div>
    @endif
</div>
