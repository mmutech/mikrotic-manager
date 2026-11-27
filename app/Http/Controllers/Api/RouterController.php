<?php

namespace App\Http\Controllers\Api;

use App\Enums\RouterJobStatus;
use App\Http\Resources\RouterHealthCheckResource;
use App\Http\Resources\RouterJobResource;
use App\Http\Resources\RouterResource;
use App\Jobs\TestRouterConnectionJob;
use App\Models\Router;
use App\Services\Api\PlanService;
use App\Services\AuditLogger;
use App\Services\RouterJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RouterController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('viewAny', Router::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Router::query()->visibleTo($user);

        if (filled($filters['search'] ?? null)) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($query) => $query
                ->where('name', 'like', $term)
                ->orWhere('identity', 'like', $term)
                ->orWhere('host', 'like', $term));
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        $routers = $query->orderBy('name')->paginate((int) ($filters['per_page'] ?? 20));

        $statusQuery = Router::query()->visibleTo($user);

        return RouterResource::collection($routers)->additional([
            'meta' => [
                'filters' => $filters,
                'summary' => [
                    'total' => (clone $statusQuery)->count(),
                    'online' => (clone $statusQuery)->where('status', 'online')->count(),
                    'needs_attention' => (clone $statusQuery)->whereIn('status', ['offline', 'error'])->count(),
                    'monitoring' => (clone $statusQuery)->monitoring()->count(),
                ],
            ],
        ]);
    }

    public function show(Request $request, Router $router): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('view', $router);

        $checks = $router->healthChecks()->latest('checked_at')->limit(6)->get();
        $pendingJobs = $router->jobs()
            ->whereIn('status', [
                RouterJobStatus::Queued->value,
                RouterJobStatus::Running->value,
                RouterJobStatus::Retrying->value,
            ])
            ->latest()
            ->limit(6)
            ->get();

        return (new RouterResource($router))->additional([
            'meta' => [
                'can_test' => Gate::forUser($user)->allows('test', $router),
                'recent_health_checks' => RouterHealthCheckResource::collection($checks)->resolve($request),
                'pending_jobs' => RouterJobResource::collection($pendingJobs)->resolve($request),
            ],
        ])->response();
    }

    public function hotspotProfiles(Request $request, Router $router, PlanService $service): JsonResponse
    {
        // Profile discovery is a read-only probe and is safe for read-only roles.
        Gate::forUser($request->user())->authorize('view', $router);

        return $this->json([
            'router_id' => $router->getKey(),
            'router_public_id' => $router->public_id,
            'profiles' => $service->hotspotProfiles($router),
        ]);
    }

    public function test(
        Request $request,
        Router $router,
        RouterJobService $jobs,
        AuditLogger $audit,
    ): JsonResponse {
        $user = $request->user();
        Gate::forUser($user)->authorize('test', $router);

        if ($jobs->pendingTestExists($router)) {
            throw ValidationException::withMessages([
                'router' => 'A connection test is already queued or running for this router.',
            ]);
        }

        $job = $jobs->create(
            router: $router,
            type: 'test_connection',
            actor: $user,
            idempotencyKey: 'router-test:'.$router->getKey(),
        );

        $audit->record('router.test_queued', $router, metadata: [
            'job_uuid' => $job->uuid,
            'source' => 'mobile-api',
        ], actor: $user);

        TestRouterConnectionJob::dispatch(
            routerId: $router->getKey(),
            routerJobId: $job->getKey(),
            actorId: $user->getKey(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        return (new RouterJobResource($job))->additional([
            'message' => 'A read-only connection test has been queued.',
        ])->response()->setStatusCode(SymfonyResponse::HTTP_ACCEPTED);
    }
}
