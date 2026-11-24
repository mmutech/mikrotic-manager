<?php

use App\Enums\ProvisioningStatus;
use App\Enums\VoucherLifecycleStatus;
use App\Jobs\ProvisionVoucherJob;
use App\Jobs\RevokeVoucherJob;
use App\Models\Voucher;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Voucher details')] class extends Component
{
    public Voucher $voucher;

    public string $provisioningDialog = 'closed';

    public string $provisioningDialogMessage = '';

    public function mount(Voucher $voucher): void
    {
        $this->authorize('view', $voucher);
        $this->voucher = $voucher->load(['plan', 'router', 'customer', 'generator']);
    }

    public function openProvisioningDialog(): void
    {
        $this->authorize('provision', $this->voucher);
        $this->provisioningDialog = 'confirm';
    }

    public function openRevokeDialog(): void
    {
        $this->authorize('revoke', $this->voucher);
        $this->provisioningDialog = 'confirm_revoke';
    }

    public function provision(): void
    {
        $this->authorize('provision', $this->voucher);

        $this->voucher->refresh();

        if (
            ! in_array($this->voucher->provisioning_status, [
                ProvisioningStatus::Pending,
                ProvisioningStatus::Failed,
            ], true)
            || ! $this->voucher->canBeProvisioned()
        ) {
            $this->provisioningDialog = 'alert';
            $this->provisioningDialogMessage = 'Only active or pending vouchers that have not expired or been exhausted can be provisioned.';

            return;
        }

        $claimed = Voucher::query()
            ->whereKey($this->voucher->getKey())
            ->whereIn('provisioning_status', [
                ProvisioningStatus::Pending->value,
                ProvisioningStatus::Failed->value,
            ])
            ->update([
                'provisioning_status' => ProvisioningStatus::Queued,
                'provisioning_error' => null,
            ]);

        if ($claimed !== 1) {
            $this->provisioningDialog = 'alert';
            $this->provisioningDialogMessage = 'This voucher is no longer eligible for provisioning. Refresh the page and try again.';

            return;
        }

        $this->voucher->refresh();
        ProvisionVoucherJob::dispatch($this->voucher->id, auth()->id())->afterCommit();
        $this->provisioningDialog = 'processing';
    }

    public function refreshProvisioningDialog(): void
    {
        if ($this->provisioningDialog !== 'processing') {
            return;
        }

        $this->authorize('view', $this->voucher);
        $this->voucher->refresh();

        if ($this->voucher->provisioning_status === ProvisioningStatus::Provisioned) {
            $this->provisioningDialog = 'success';
        } elseif ($this->voucher->provisioning_status === ProvisioningStatus::Failed) {
            $this->provisioningDialog = 'failure';
        }
    }

    public function closeProvisioningDialog(): void
    {
        $this->provisioningDialog = 'closed';
        $this->provisioningDialogMessage = '';
    }

    public function revoke(): void
    {
        $this->authorize('revoke', $this->voucher);
        $this->voucher->refresh();

        if ($this->voucher->lifecycle_status === VoucherLifecycleStatus::Revoked
            || $this->voucher->provisioning_status === ProvisioningStatus::Removed
            || $this->voucher->provisioning_status === ProvisioningStatus::Disabling
        ) {
            $this->provisioningDialog = 'alert';
            $this->provisioningDialogMessage = 'This voucher is not eligible for revocation.';

            return;
        }

        $claimed = Voucher::query()
            ->whereKey($this->voucher->getKey())
            ->where('lifecycle_status', '!=', VoucherLifecycleStatus::Revoked->value)
            ->whereNotIn('provisioning_status', [
                ProvisioningStatus::Removed->value,
                ProvisioningStatus::Disabling->value,
            ])
            ->update([
                'provisioning_status' => ProvisioningStatus::Disabling,
                'provisioning_error' => null,
            ]);

        if ($claimed !== 1) {
            $this->provisioningDialog = 'alert';
            $this->provisioningDialogMessage = 'This voucher is no longer eligible for revocation. Refresh the page and try again.';

            return;
        }

        RevokeVoucherJob::dispatch($this->voucher->id, auth()->id());
        session()->flash('status', 'Revocation queued.');
        $this->closeProvisioningDialog();
        $this->redirectRoute('vouchers.show', ['voucher' => $this->voucher->public_id], navigate: true);
    }

    public function render()
    {
        $user = auth()->user();
        $this->voucher = $this->voucher->fresh()->load(['plan', 'router', 'customer', 'generator']);

        return $this->view([
            'canRevoke' => $user->can('revoke', $this->voucher)
                && $this->voucher->lifecycle_status !== VoucherLifecycleStatus::Revoked
                && ! in_array($this->voucher->provisioning_status, [
                    ProvisioningStatus::Removed,
                    ProvisioningStatus::Disabling,
                ], true),
            'canProvision' => $user->can('provision', $this->voucher)
                && in_array($this->voucher->provisioning_status, [
                    ProvisioningStatus::Pending,
                    ProvisioningStatus::Failed,
                ], true)
                && $this->voucher->canBeProvisioned(),
            'canPrint' => $user->can('print', $this->voucher)
                && $this->voucher->provisioning_status === ProvisioningStatus::Provisioned
                && $this->voucher->canBePrinted(),
            'hasOperationFailure' => in_array($this->voucher->provisioning_status, [
                ProvisioningStatus::Failed,
                ProvisioningStatus::RevocationFailed,
            ], true),
        ])->title('Voucher '.$this->voucher->username);
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('vouchers.index') }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to vouchers</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900 font-mono">{{ $voucher->username }}</h1>
    </div>

    <style>
        .voucher-print-ticket {
            display: none;
        }
    </style>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="mm-card p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">Voucher Details</h2>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Router</dt>
                    <dd class="text-sm text-slate-900">{{ $voucher->router->name }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Plan</dt>
                    <dd class="text-sm text-slate-900">{{ $voucher->plan?->name ?? $voucher->hotspot_profile ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Lifecycle Status</dt>
                    <dd class="text-sm text-slate-900">{{ $voucher->lifecycle_status->value }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Provisioning Status</dt>
                    <dd class="text-sm text-slate-900">{{ $voucher->provisioning_status->value }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Generated By</dt>
                    <dd class="text-sm text-slate-900">{{ $voucher->generator->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Created</dt>
                    <dd class="text-sm text-slate-900">{{ $voucher->created_at->format('Y-m-d H:i:s') }}</dd>
                </div>
            </dl>
        </div>

        <div class="mm-card p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">Usage</h2>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Data Allowance</dt>
                    <dd class="text-sm text-slate-900">{{ $voucher->plan?->quotaLabel() ?? $voucher->quotaLabel() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Used Bytes</dt>
                    <dd class="text-sm text-slate-900">{{ number_format($voucher->used_bytes) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Remaining</dt>
                    <dd class="text-sm text-slate-900">{{ $voucher->remainingBytes() !== null ? number_format($voucher->remainingBytes()) : 'Unlimited' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-sm text-slate-500">Expires</dt>
                    <dd class="text-sm text-slate-900">{{ $voucher->expires_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
            </dl>
        </div>
    </div>

    @if ($hasOperationFailure && $provisioningDialog === 'closed')
        @include('partials.alert-modal', [
            'title' => $voucher->provisioning_status === ProvisioningStatus::RevocationFailed
                ? 'Voucher revocation failed'
                : 'Voucher provisioning failed',
            'message' => filled($voucher->provisioning_error)
                ? $voucher->provisioning_error
                : 'No failure details were recorded. Retry the operation to capture the error.',
            'type' => 'error',
            'open' => true,
            'listen' => false,
        ])
    @endif

    <div class="mm-card p-4 flex gap-3">
        @if ($canProvision)
            <button wire:click="openProvisioningDialog" class="mm-btn-primary">Provision to Router</button>
        @endif
        @if ($canRevoke)
            <button wire:click="openRevokeDialog" class="mm-btn-danger">Revoke Voucher</button>
        @endif
        @if ($canPrint)
            <button type="button" onclick="window.printVoucherContent('.voucher-print-ticket')" class="mm-btn-secondary">Print voucher</button>
        @endif
    </div>

    @if ($canPrint)
        <section class="voucher-print-ticket" aria-label="Printable voucher">
            <h1 class="text-2xl font-bold">{{ $voucher->router->name }}</h1>
            <p class="mt-1 text-sm font-semibold">Wi-Fi Voucher</p>
            <p class="mt-2 text-sm">Enter this code to access the internet</p>
            <p class="voucher-code mt-6 rounded-lg border border-slate-300 px-4 py-5 text-center font-mono text-3xl font-bold tracking-wider">
                {{ $voucher->username }}
            </p>
            <p class="mt-4 text-sm">{{ $voucher->plan?->name ?? $voucher->hotspot_profile ?? 'Voucher' }} · {{ $voucher->router->name }}</p>
        </section>
    @endif

    @if ($provisioningDialog !== 'closed')
        <div
            class="fixed inset-0 z-[60] flex items-end justify-center bg-slate-950/60 p-0 backdrop-blur-sm sm:items-center sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="voucher-provisioning-title"
            @if ($provisioningDialog === 'processing') wire:poll.3s="refreshProvisioningDialog" @endif
        >
            <button type="button" class="absolute inset-0 h-full w-full cursor-default" wire:click="closeProvisioningDialog" aria-label="Close dialog"></button>
            <div class="relative z-10 w-full rounded-t-3xl bg-white p-5 shadow-2xl sm:max-w-lg sm:rounded-3xl sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <h2 id="voucher-provisioning-title" class="text-lg font-semibold text-slate-900">
                        @if ($provisioningDialog === 'confirm')
                            Provision this voucher?
                        @elseif ($provisioningDialog === 'confirm_revoke')
                            Revoke this voucher?
                        @elseif ($provisioningDialog === 'processing')
                            Provisioning voucher
                        @elseif ($provisioningDialog === 'success')
                            Voucher provisioned
                        @elseif ($provisioningDialog === 'failure')
                            Voucher provisioning failed
                        @else
                            Unable to provision voucher
                        @endif
                    </h2>
                    <button type="button" wire:click="closeProvisioningDialog" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close dialog">×</button>
                </div>

                <p class="mt-3 text-sm leading-6 text-slate-600">
                    @if ($provisioningDialog === 'confirm')
                        Send this voucher to {{ $voucher->router->name }} now?
                    @elseif ($provisioningDialog === 'confirm_revoke')
                        Remove this voucher from {{ $voucher->router->name }}?
                    @elseif ($provisioningDialog === 'processing')
                        Provisioning is queued. This dialog will update when the router finishes.
                    @elseif ($provisioningDialog === 'success')
                        Provisioning completed successfully. This voucher is ready to print.
                    @elseif ($provisioningDialog === 'failure')
                        {{ filled($voucher->provisioning_error) ? $voucher->provisioning_error : 'No failure details were recorded.' }}
                    @else
                        {{ $provisioningDialogMessage }}
                    @endif
                </p>

                <div class="mt-6 flex justify-end gap-2">
                    @if (in_array($provisioningDialog, ['confirm', 'confirm_revoke'], true))
                        <button type="button" wire:click="closeProvisioningDialog" class="mm-btn-secondary">Cancel</button>
                        @if ($provisioningDialog === 'confirm')
                            <button type="button" wire:click="provision" wire:loading.attr="disabled" class="mm-btn-primary">Provision voucher</button>
                        @else
                            <button type="button" wire:click="revoke" wire:loading.attr="disabled" class="mm-btn-danger">Revoke voucher</button>
                        @endif
                    @else
                        <button type="button" wire:click="closeProvisioningDialog" class="mm-btn-primary">
                            {{ $provisioningDialog === 'processing' ? 'Continue in background' : 'Close' }}
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
