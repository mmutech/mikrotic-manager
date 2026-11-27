<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\VoucherResource;
use App\Models\Router;
use App\Models\Voucher;
use App\Services\Api\ReportService;
use App\Services\Api\VoucherService;
use App\Services\Vouchers\LiveVoucherUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class VoucherController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('viewAny', Voucher::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'router' => ['nullable', 'string', 'max:64'],
            'lifecycle' => ['nullable', 'string', 'max:40'],
            'provisioning' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Voucher::query()->with(['router:id,name', 'plan:id,name', 'customer:id,name']);

        if (! $user->isSuperAdmin()) {
            $query->whereIn('router_id', Router::query()->visibleTo($user)->select('routers.id'));
        }

        if (filled($filters['search'] ?? null)) {
            $query->where('username', 'like', '%'.$filters['search'].'%');
        }

        if (filled($filters['router'] ?? null)) {
            $query->where('router_id', $this->resolveRouterId($user, (string) $filters['router']));
        }

        if (filled($filters['lifecycle'] ?? null)) {
            $query->where('lifecycle_status', $filters['lifecycle']);
        }

        if (filled($filters['provisioning'] ?? null)) {
            $query->where('provisioning_status', $filters['provisioning']);
        }

        $vouchers = $query->latest()->paginate((int) ($filters['per_page'] ?? 20));
        $liveUsage = app(LiveVoucherUsageService::class)->forVouchers($vouchers->getCollection());

        foreach ($vouchers as $voucher) {
            $voucher->setAttribute('live_usage', $liveUsage->get($voucher->getKey()));
        }

        return VoucherResource::collection($vouchers)->additional([
            'meta' => ['filters' => $filters],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('viewAny', Voucher::class);

        $stats = app(ReportService::class)->voucherStats($user);
        $stats['recent'] = VoucherResource::collection($stats['recent'])->resolve($request);

        return $this->json($stats);
    }

    public function generate(Request $request, VoucherService $service): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('generate', Voucher::class);
        $this->rejectUnsupportedFields($request);

        $validated = $request->validate([
            'router_id' => ['required', 'string', 'max:64'],
            'plan_id' => ['required', 'string', 'max:64'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'prefix' => ['nullable', 'string', 'max:10', 'alpha_num'],
        ]);

        $vouchers = $service->generate($user, $validated)->load(['router:id,name', 'plan:id,name']);

        foreach ($vouchers as $voucher) {
            $voucher->setAttribute('show_credentials', true);
        }

        return VoucherResource::collection($vouchers)->additional([
            'message' => "Generated {$vouchers->count()} voucher(s).",
        ])->response()->setStatusCode(SymfonyResponse::HTTP_CREATED);
    }

    public function show(Request $request, Voucher $voucher): VoucherResource
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('view', $voucher);

        $voucher->load(['router:id,name', 'plan:id,name', 'customer:id,name']);
        $voucher->setAttribute('live_usage', app(LiveVoucherUsageService::class)->forVoucher($voucher));

        return new VoucherResource($voucher);
    }

    public function update(Request $request, Voucher $voucher, VoucherService $service): VoucherResource
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('update', $voucher);
        $this->rejectUnsupportedFields($request);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $voucher = $service->update($user, $voucher, $validated)->load(['router:id,name', 'plan:id,name']);

        return new VoucherResource($voucher);
    }

    public function provision(Request $request, Voucher $voucher, VoucherService $service): VoucherResource
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('provision', $voucher);

        $voucher = $service->provision($user, $voucher)->load(['router:id,name', 'plan:id,name']);
        $voucher->setAttribute('live_usage', app(LiveVoucherUsageService::class)->forVoucher($voucher));

        return (new VoucherResource($voucher))->additional([
            'message' => 'Provisioning queued.',
        ]);
    }

    public function revoke(Request $request, Voucher $voucher, VoucherService $service): VoucherResource
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('revoke', $voucher);

        $voucher = $service->revoke($user, $voucher)->load(['router:id,name', 'plan:id,name']);
        $voucher->setAttribute('live_usage', app(LiveVoucherUsageService::class)->forVoucher($voucher));

        return (new VoucherResource($voucher))->additional([
            'message' => 'Revocation queued.',
        ]);
    }

    public function provisionBulk(Request $request, VoucherService $service): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('viewAny', Voucher::class);
        abort_unless($user->can('vouchers.provision'), 403);
        $this->rejectUnsupportedFields($request);

        $validated = $request->validate([
            'voucher_ids' => ['required', 'array', 'min:1', 'max:100'],
            'voucher_ids.*' => ['required', 'string', 'max:64', 'distinct'],
        ]);

        $result = $service->provisionBulk($user, $validated['voucher_ids']);

        return $this->json($result, 'Provisioning queued.', SymfonyResponse::HTTP_ACCEPTED);
    }

    public function revokeBulk(Request $request, VoucherService $service): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('viewAny', Voucher::class);
        abort_unless($user->can('vouchers.revoke'), 403);
        $this->rejectUnsupportedFields($request);

        $validated = $request->validate([
            'voucher_ids' => ['required', 'array', 'min:1', 'max:100'],
            'voucher_ids.*' => ['required', 'string', 'max:64', 'distinct'],
        ]);

        $result = $service->revokeBulk($user, $validated['voucher_ids']);

        return $this->json($result, 'Revocation queued.', SymfonyResponse::HTTP_ACCEPTED);
    }

    private function resolveRouterId($user, string $identifier): int
    {
        $query = Router::query()->visibleTo($user);

        if (is_numeric($identifier)) {
            $query->whereKey((int) $identifier);
        } else {
            $query->where('public_id', $identifier);
        }

        return (int) $query->firstOrFail()->getKey();
    }
}
