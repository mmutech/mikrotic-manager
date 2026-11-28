<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\Router;
use App\Services\Api\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PlanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('viewAny', Plan::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'router' => ['nullable', 'string', 'max:64'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Plan::query()->with(['router:id,name', 'profileMappings']);

        if (! $user->isSuperAdmin()) {
            $query->whereIn('router_id', Router::query()->visibleTo($user)->select('routers.id'));
        }

        if (filled($filters['search'] ?? null)) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($query) => $query
                ->where('name', 'like', $term)
                ->orWhere('code', 'like', $term));
        }

        if (filled($filters['router'] ?? null)) {
            $query->where('router_id', $this->resolveRouterId($user, (string) $filters['router']));
        }

        if (array_key_exists('active', $filters) && $filters['active'] !== null) {
            $query->where('is_active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        return PlanResource::collection($query->latest()->paginate((int) ($filters['per_page'] ?? 10)))->additional([
            'meta' => ['filters' => $filters],
        ]);
    }

    public function show(Request $request, Plan $plan): PlanResource
    {
        Gate::forUser($request->user())->authorize('view', $plan);

        return new PlanResource($plan->load(['router:id,name', 'profileMappings']));
    }

    public function store(Request $request, PlanService $service): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('create', Plan::class);
        $this->rejectUnsupportedFields($request);

        $request->merge([
            'is_unlimited' => $request->input('is_unlimited', false),
            'is_active' => $request->input('is_active', true),
        ]);

        $validated = $request->validate($this->validationRules(creating: true));

        $plan = $service->store($user, $validated);

        return (new PlanResource($plan))->response()->setStatusCode(SymfonyResponse::HTTP_CREATED);
    }

    public function update(Request $request, Plan $plan, PlanService $service): PlanResource
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('update', $plan);
        $this->rejectUnsupportedFields($request);

        $mapping = $plan->profileMappings()
            ->where('router_id', $plan->router_id)
            ->first();

        $defaults = [
            'name' => $plan->name,
            'hotspot_profile' => $mapping?->hotspot_profile,
            'description' => $plan->description,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'is_unlimited' => $plan->is_unlimited,
            'data_allowance_mb' => $plan->is_unlimited
                ? null
                : (int) round(((int) $plan->data_allowance_bytes) / 1048576),
            'validity_days' => $plan->validity_seconds === null
                ? null
                : (int) round(((int) $plan->validity_seconds) / 86400),
            'time_allowance_seconds' => $plan->time_allowance_seconds,
            'is_active' => $plan->is_active,
        ];

        foreach ($defaults as $key => $value) {
            if (! $request->has($key)) {
                $request->merge([$key => $value]);
            }
        }

        $validated = $request->validate($this->validationRules(creating: false));

        return new PlanResource($service->update($user, $plan, $validated));
    }

    public function destroy(Request $request, Plan $plan, PlanService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('delete', $plan);

        $service->destroy($request->user(), $plan);

        return $this->json([], 'Plan deleted.', SymfonyResponse::HTTP_OK);
    }

    /** @return array<string, array<int, mixed>> */
    private function validationRules(bool $creating): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'hotspot_profile' => [
                'required',
                'string',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/',
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'is_unlimited' => ['boolean'],
            'data_allowance_mb' => ['exclude_if:is_unlimited,true', 'required', 'integer', 'min:1'],
            'validity_days' => ['nullable', 'integer', 'min:1'],
            'time_allowance_seconds' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'upload_speed_kbps' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'required_with:download_speed_kbps'],
            'download_speed_kbps' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'required_with:upload_speed_kbps'],
            'is_active' => ['boolean'],
        ];

        if ($creating) {
            $rules['router_id'] = ['required', 'string', 'max:64'];
        }

        return $rules;
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
