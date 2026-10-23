<?php

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\Transaction;
use App\Models\Voucher;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Reports')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('reports.view');
    }

    public function render()
    {
        $user = auth()->user();
        $routerIds = $user->isSuperAdmin() ? null : $user->routers()->pluck('routers.id');

        $plansQuery = Plan::query();
        $vouchersQuery = Voucher::query();
        $customersQuery = Customer::query();
        $purchasesQuery = Purchase::query();
        $transactionsQuery = Transaction::query();

        if ($routerIds !== null) {
            if ($routerIds->isEmpty()) {
                $plansQuery->whereRaw('1=0');
                $vouchersQuery->whereRaw('1=0');
                $customersQuery->whereRaw('1=0');
                $purchasesQuery->whereRaw('1=0');
                $transactionsQuery->whereRaw('1=0');
            } else {
                $vouchersQuery->whereIn('router_id', $routerIds);
                $purchasesQuery->whereIn('router_id', $routerIds);
                $transactionsQuery->whereIn('router_id', $routerIds);
                $customersQuery->where(function ($q) use ($routerIds) {
                    $q->whereHas('purchases', fn ($pq) => $pq->whereIn('router_id', $routerIds))
                        ->orWhereHas('transactions', fn ($tq) => $tq->whereIn('router_id', $routerIds))
                        ->orWhereHas('vouchers', fn ($vq) => $vq->whereIn('router_id', $routerIds));
                });
            }
        }

        return $this->view([
            'plansCount' => $plansQuery->count(),
            'activePlans' => $plansQuery->clone()->where('is_active', true)->count(),
            'vouchersCount' => $vouchersQuery->count(),
            'vouchersActive' => $vouchersQuery->clone()->where('lifecycle_status', 'active')->count(),
            'customersCount' => $customersQuery->count(),
            'purchasesCount' => $purchasesQuery->count(),
            'transactionsCount' => $transactionsQuery->count(),
            'revenue' => (clone $transactionsQuery)->where('status', 'success')->sum('amount'),
        ])->title('Reports');
    }
};
?>

<div class="space-y-6">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-600">Analytics</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Reports</h1>
        <p class="mt-1 text-sm text-slate-500">Overview of plans, vouchers, customers, and revenue.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="mm-card p-5">
            <p class="text-sm text-slate-500">Plans (Active/Total)</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ $activePlans }} / {{ $plansCount }}</p>
        </div>
        <div class="mm-card p-5">
            <p class="text-sm text-slate-500">Vouchers (Active/Total)</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ $vouchersActive }} / {{ $vouchersCount }}</p>
        </div>
        <div class="mm-card p-5">
            <p class="text-sm text-slate-500">Customers</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ $customersCount }}</p>
        </div>
        <div class="mm-card p-5">
            <p class="text-sm text-slate-500">Successful Revenue</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">NGN {{ number_format($revenue, 2) }}</p>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="mm-card p-5">
            <p class="text-sm text-slate-500">Purchases</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ $purchasesCount }}</p>
        </div>
        <div class="mm-card p-5">
            <p class="text-sm text-slate-500">Transactions</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ $transactionsCount }}</p>
        </div>
    </div>
</div>
