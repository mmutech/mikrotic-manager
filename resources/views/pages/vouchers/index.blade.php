<?php

use App\Enums\ProvisioningStatus;
use App\Jobs\ProvisionVoucherJob;
use App\Models\Router;
use App\Models\Voucher;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::app')] #[Title('Vouchers')] class extends Component
{
    use WithPagination;

    private const MAX_BULK_VOUCHERS = 100;

    public string $search = '';

    public string $router = '';

    public string $lifecycle = '';

    /** @var array<int, int|string> */
    public array $selectedVouchers = [];

    /** @var array<int, int> */
    public array $printingVoucherIds = [];

    public string $provisioningModalState = 'closed';

    public string $provisioningAlertMessage = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selectedVouchers = [];
        $this->resetErrorBag('selectedVouchers');
    }

    public function updatedRouter(): void
    {
        $this->resetPage();
        $this->selectedVouchers = [];
        $this->resetErrorBag('selectedVouchers');
    }

    public function updatedLifecycle(): void
    {
        $this->resetPage();
        $this->selectedVouchers = [];
        $this->resetErrorBag('selectedVouchers');
    }

    public function clearSelection(): void
    {
        $this->selectedVouchers = [];
        $this->resetErrorBag('selectedVouchers');
    }

    public function openProvisioningConfirmation(): void
    {
        $this->authorize('viewAny', Voucher::class);
        abort_unless(auth()->user()->can('vouchers.provision'), 403);

        $this->resetErrorBag('selectedVouchers');
        $this->provisioningModalState = 'confirm';
    }

    public function closeProvisioningModal(): void
    {
        $this->provisioningModalState = 'closed';
        $this->provisioningAlertMessage = '';
        $this->resetErrorBag('selectedVouchers');
    }

    public function clearPrintingVoucherIds(): void
    {
        $this->printingVoucherIds = [];
    }

    public function provisionSelected(): void
    {
        $this->authorize('viewAny', Voucher::class);
        abort_unless(auth()->user()->can('vouchers.provision'), 403);

        $validated = $this->validate([
            'selectedVouchers' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK_VOUCHERS],
            'selectedVouchers.*' => ['required', 'integer', 'distinct'],
        ]);
        $selectedIds = array_values(array_unique(array_map('intval', $validated['selectedVouchers'])));

        if (count($selectedIds) !== count($validated['selectedVouchers'])) {
            $this->addError('selectedVouchers', 'Select each voucher only once.');

            return;
        }

        $user = auth()->user();
        $query = Voucher::query()->whereKey($selectedIds);

        if (! $user->isSuperAdmin()) {
            $query->whereIn('router_id', $user->routers()->pluck('routers.id'));
        }

        $vouchers = $query->get();

        if ($vouchers->count() !== count($selectedIds)) {
            $this->addError('selectedVouchers', 'One or more selected vouchers are unavailable.');

            return;
        }

        foreach ($vouchers as $voucher) {
            $this->authorize('view', $voucher);
            $this->authorize('provision', $voucher);

            if (
                ! in_array($voucher->provisioning_status, [
                    ProvisioningStatus::Pending,
                    ProvisioningStatus::Failed,
                ], true)
                || ! $voucher->canBeProvisioned()
            ) {
                $this->addError('selectedVouchers', 'Only active or pending vouchers that have not expired or been exhausted can be provisioned.');

                return;
            }
        }

        $queued = 0;

        foreach ($vouchers as $voucher) {
            $updated = Voucher::query()
                ->whereKey($voucher->getKey())
                ->whereIn('provisioning_status', [
                    ProvisioningStatus::Pending->value,
                    ProvisioningStatus::Failed->value,
                ])
                ->update([
                    'provisioning_status' => ProvisioningStatus::Queued,
                    'provisioning_error' => null,
                ]);

            if ($updated !== 1) {
                continue;
            }

            ProvisionVoucherJob::dispatch($voucher->getKey(), $user->getKey())->afterCommit();
            $queued++;
        }

        $this->selectedVouchers = [];

        if ($queued === 0) {
            $this->provisioningModalState = 'alert';
            $this->provisioningAlertMessage = 'None of the selected vouchers were still pending or failed.';

            return;
        }

        $this->provisioningModalState = 'alert';
        $this->provisioningAlertMessage =
            $queued === count($selectedIds)
                ? "Queued {$queued} voucher(s) for provisioning."
                : "Queued {$queued} of ".count($selectedIds).' selected voucher(s); the others were no longer eligible.';
    }

    public function printSelected(): void
    {
        $this->authorize('viewAny', Voucher::class);
        abort_unless(auth()->user()->can('vouchers.print'), 403);

        $validated = $this->validate([
            'selectedVouchers' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK_VOUCHERS],
            'selectedVouchers.*' => ['required', 'integer', 'distinct'],
        ]);
        $selectedIds = array_values(array_unique(array_map('intval', $validated['selectedVouchers'])));

        if (count($selectedIds) !== count($validated['selectedVouchers'])) {
            $this->addError('selectedVouchers', 'Select each voucher only once.');

            return;
        }

        $user = auth()->user();
        $query = Voucher::query()
            ->with(['plan', 'router'])
            ->whereKey($selectedIds);

        if (! $user->isSuperAdmin()) {
            $query->whereIn('router_id', $user->routers()->pluck('routers.id'));
        }

        $vouchers = $query->get();

        if ($vouchers->count() !== count($selectedIds)) {
            $this->addError('selectedVouchers', 'One or more selected vouchers are unavailable.');

            return;
        }

        foreach ($vouchers as $voucher) {
            $this->authorize('view', $voucher);
            $this->authorize('print', $voucher);

            if ($voucher->provisioning_status !== ProvisioningStatus::Provisioned
                || ! $voucher->canBePrinted()
            ) {
                $this->addError('selectedVouchers', 'Only active, provisioned vouchers that have not expired or been exhausted can be printed.');

                return;
            }
        }

        $this->printingVoucherIds = $vouchers->modelKeys();
        $this->selectedVouchers = [];
        $this->dispatch('print-vouchers');
    }

    public function render()
    {
        $this->authorize('viewAny', Voucher::class);

        $query = Voucher::query()->with(['plan', 'router', 'customer']);

        $user = auth()->user();
        if (! $user->isSuperAdmin()) {
            $query->whereIn('router_id', $user->routers()->pluck('routers.id'));
        }

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where('username', 'like', $term);
        }
        if ($this->router !== '') {
            $query->where('router_id', $this->router);
        }
        if ($this->lifecycle !== '') {
            $query->where('lifecycle_status', $this->lifecycle);
        }

        $canPrint = $user->can('vouchers.print');
        $printingVouchers = collect();
        $selectedIds = array_values(array_unique(array_map('intval', $this->selectedVouchers)));
        $selectedQuery = Voucher::query()->whereKey($selectedIds);

        if (! $user->isSuperAdmin()) {
            $selectedQuery->whereIn('router_id', $user->routers()->pluck('routers.id'));
        }

        $selectedVoucherRecords = $selectedIds === [] ? collect() : $selectedQuery->get();
        $selectionIsAvailable = count($selectedIds) === count($this->selectedVouchers)
            && $selectedVoucherRecords->count() === count($selectedIds);
        $canPrintSelected = $canPrint
            && $selectedIds !== []
            && $selectionIsAvailable
            && $selectedVoucherRecords->every(
                fn (Voucher $voucher): bool => $voucher->provisioning_status === ProvisioningStatus::Provisioned
                    && $voucher->canBePrinted(),
            );
        $canProvisionSelected = $user->can('vouchers.provision')
            && $selectedIds !== []
            && $selectionIsAvailable
            && $selectedVoucherRecords->every(fn (Voucher $voucher): bool => in_array(
                $voucher->provisioning_status,
                [ProvisioningStatus::Pending, ProvisioningStatus::Failed],
                true,
            ) && $voucher->canBeProvisioned());

        if ($canPrint && $this->printingVoucherIds !== []) {
            $printQuery = Voucher::query()
                ->with(['plan', 'router'])
                ->whereKey($this->printingVoucherIds);

            if (! $user->isSuperAdmin()) {
                $printQuery->whereIn('router_id', $user->routers()->pluck('routers.id'));
            }

            $printingVouchers = $printQuery->get();

            foreach ($printingVouchers as $voucher) {
                $this->authorize('view', $voucher);
                $this->authorize('print', $voucher);
            }
        }

        return $this->view([
            'vouchers' => $query->latest()->paginate(20),
            'routers' => Router::query()->orderBy('name')->get(),
            'canGenerate' => auth()->user()->can('generate', Voucher::class),
            'canProvision' => auth()->user()->can('vouchers.provision'),
            'canPrint' => $canPrint,
            'canPrintSelected' => $canPrintSelected,
            'canProvisionSelected' => $canProvisionSelected,
            'printingVouchers' => $printingVouchers,
        ])->title('Vouchers');
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Hotspot Access</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Vouchers</h1>
            <p class="mt-1 text-sm text-slate-500">Generate and manage hotspot vouchers separately from router provisioning.</p>
        </div>
        @if ($canGenerate)
            <a href="{{ route('vouchers.generate') }}" class="mm-btn-primary">Generate vouchers</a>
        @endif
    </div>

    <div class="mm-card overflow-hidden">
        <div class="border-b border-slate-200 p-4 grid gap-3 sm:grid-cols-3">
            <input type="search" wire:model.live.debounce.300ms="search" class="mm-input" placeholder="Search username...">
            <select wire:model.live="router" class="mm-input">
                <option value="">All routers</option>
                @foreach ($routers as $r)
                    <option value="{{ $r->id }}">{{ $r->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="lifecycle" class="mm-input">
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="active">Active</option>
                <option value="used">Used</option>
                <option value="expired">Expired</option>
                <option value="revoked">Revoked</option>
            </select>
        </div>
        @if ($canProvision || $canPrint)
            <div class="flex flex-col gap-2 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-slate-500">
                    @if ($canPrint && $canProvision)
                        Select up to 100 vouchers to print or provision; provisioning is limited to eligible active or pending vouchers.
                    @elseif ($canPrint)
                        Select up to 100 vouchers to print.
                    @else
                        Select up to 100 eligible pending or failed vouchers to provision.
                    @endif
                </p>
                <div class="flex flex-wrap gap-2">
                    @if ($canPrint)
                        <button
                            type="button"
                            wire:click="printSelected"
                            wire:loading.attr="disabled"
                            class="mm-btn-secondary"
                            @disabled(! $canPrintSelected)
                        >
                            Print selected ({{ count($selectedVouchers) }})
                        </button>
                    @endif
                    @if ($canProvision)
                        <button
                            type="button"
                            wire:click="openProvisioningConfirmation"
                            wire:loading.attr="disabled"
                            class="mm-btn-primary"
                            @disabled(! $canProvisionSelected)
                        >
                            Provision selected ({{ count($selectedVouchers) }})
                        </button>
                    @endif
                    @if ($selectedVouchers !== [])
                        <button type="button" wire:click="clearSelection" class="mm-btn-secondary">Clear selection</button>
                    @endif
                </div>
                @error('selectedVouchers') <p class="mm-error sm:basis-full">{{ $message }}</p> @enderror
            </div>
        @endif
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        @if ($canProvision || $canPrint)
                            <th class="mm-th"><span class="sr-only">Select</span></th>
                        @endif
                        <th class="mm-th">Username</th>
                        <th class="mm-th">Plan</th>
                        <th class="mm-th">Router</th>
                        <th class="mm-th">Lifecycle</th>
                        <th class="mm-th">Provisioning</th>
                        <th class="mm-th">Created</th>
                        <th class="mm-th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($vouchers as $voucher)
                        <tr>
                            @if ($canProvision || $canPrint)
                                <td class="mm-td">
                                    @if (($canPrint && $voucher->provisioning_status === ProvisioningStatus::Provisioned && $voucher->canBePrinted())
                                        || ($canProvision
                                            && in_array($voucher->provisioning_status, [ProvisioningStatus::Pending, ProvisioningStatus::Failed], true)
                                            && $voucher->canBeProvisioned()))
                                        <input
                                            type="checkbox"
                                            wire:model.live="selectedVouchers"
                                            value="{{ $voucher->getKey() }}"
                                            aria-label="Select voucher {{ $voucher->username }}"
                                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                        >
                                    @endif
                                </td>
                            @endif
                            <td class="mm-td font-mono text-sm">{{ $voucher->username }}</td>
                            <td class="mm-td">{{ $voucher->plan->name ?? '—' }}</td>
                            <td class="mm-td">{{ $voucher->router->name ?? '—' }}</td>
                            <td class="mm-td">
                                <span class="text-xs">{{ $voucher->lifecycle_status->value }}</span>
                            </td>
                            <td class="mm-td">
                                <span class="text-xs">{{ $voucher->provisioning_status->value }}</span>
                            </td>
                            <td class="mm-td text-xs text-slate-500">{{ $voucher->created_at->format('Y-m-d H:i') }}</td>
                            <td class="mm-td text-right">
                                <a href="{{ route('vouchers.show', $voucher) }}" class="text-sm font-semibold text-brand-600 hover:text-brand-700">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($vouchers->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $vouchers->links() }}</div>
        @endif
    </div>

    @if ($provisioningModalState !== 'closed')
        <div class="fixed inset-0 z-[60] flex items-end justify-center bg-slate-950/60 p-0 backdrop-blur-sm sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-labelledby="bulk-voucher-provisioning-title">
            <button type="button" class="absolute inset-0 h-full w-full cursor-default" wire:click="closeProvisioningModal" aria-label="Close dialog"></button>
            <div class="relative z-10 w-full rounded-t-3xl bg-white p-5 shadow-2xl sm:max-w-lg sm:rounded-3xl sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <h2 id="bulk-voucher-provisioning-title" class="text-lg font-semibold text-slate-900">
                        {{ $provisioningModalState === 'confirm' ? 'Provision selected vouchers?' : 'Provisioning alert' }}
                    </h2>
                    <button type="button" wire:click="closeProvisioningModal" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close dialog">×</button>
                </div>
                <p class="mt-3 text-sm leading-6 text-slate-600">
                    {{ $provisioningModalState === 'confirm'
                        ? 'Queue '.count($selectedVouchers).' selected voucher(s) for their routers?'
                        : $provisioningAlertMessage }}
                </p>
                @error('selectedVouchers') <p class="mm-error mt-3">{{ $message }}</p> @enderror
                <div class="mt-6 flex justify-end gap-2">
                    @if ($provisioningModalState === 'confirm')
                        <button type="button" wire:click="closeProvisioningModal" class="mm-btn-secondary">Cancel</button>
                        <button type="button" wire:click="provisionSelected" wire:loading.attr="disabled" class="mm-btn-primary">Provision selected</button>
                    @else
                        <button type="button" wire:click="closeProvisioningModal" class="mm-btn-primary">Close</button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if ($canPrint && $printingVouchers->isNotEmpty())
        <div class="voucher-print-sheet" aria-label="Printable vouchers">
            @foreach ($printingVouchers as $voucher)
                <section class="voucher-print-ticket">
                    <h2 class="text-lg font-bold">{{ $voucher->router->name }}</h2>
                    <p class="mt-1 text-xs font-semibold">Wi-Fi Voucher</p>
                    <p class="mt-1 text-xs">Enter this code to access the internet</p>
                    <p class="voucher-code mt-4 rounded-lg border border-slate-300 px-3 py-4 text-center font-mono text-2xl font-bold tracking-wider">
                        {{ $voucher->username }}
                    </p>
                    <p class="mt-3 text-xs">{{ $voucher->plan?->name ?? $voucher->hotspot_profile ?? 'Voucher' }} · {{ $voucher->router->name }}</p>
                </section>
            @endforeach
        </div>
    @endif

    <style>
        .voucher-print-sheet {
            display: none;
        }
    </style>

    @script
        <script>
            $wire.on('print-vouchers', async () => {
                window.printVoucherContent('.voucher-print-sheet');
                await $wire.clearPrintingVoucherIds();
            });
        </script>
    @endscript
</div>
