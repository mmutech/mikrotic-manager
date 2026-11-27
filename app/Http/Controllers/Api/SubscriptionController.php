<?php

namespace App\Http\Controllers\Api;

use App\Enums\SubscriptionType;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\UserResource;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SubscriptionPricingService;
use App\Services\SubscriptionService;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SubscriptionController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->json([
            'requires_subscription' => $user->requiresSubscription(),
            'has_active_subscription' => $user->hasActiveSubscription(),
            'active' => $user->activeSubscription() !== null
                ? new SubscriptionResource($user->activeSubscription())
                : null,
            'pending' => $user->subscriptions()->where('status', 'pending')->exists(),
            'history' => SubscriptionResource::collection(
                $user->subscriptions()->latest()->limit(10)->get()
            )->resolve($request),
        ]);
    }

    public function plans(SubscriptionPricingService $pricing): JsonResponse
    {
        return $this->json($pricing->options(), 'Subscription plan types.');
    }

    public function requestRenewal(Request $request, AuditLogger $audit, SubscriptionPricingService $pricing): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isSuperAdmin() || ! $user->requiresSubscription()) {
            return $this->json([
                'requires_subscription' => false,
                'pending' => false,
            ], 'No subscription is required for your account.');
        }

        if ($user->subscriptions()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'subscription' => 'You already have a pending renewal request.',
            ]);
        }

        $validated = $request->validate([
            'type' => ['sometimes', Rule::enum(SubscriptionType::class)],
        ]);

        $type = isset($validated['type'])
            ? SubscriptionType::from($validated['type'])
            : SubscriptionType::Monthly;

        $subscription = Subscription::create([
            'user_id' => $user->getKey(),
            'plan' => $type->value,
            'type' => $type,
            'status' => 'pending',
            'amount' => $pricing->priceFor($type),
            'currency' => $pricing->currency(),
            'metadata' => ['requested_at' => now()->toIso8601String(), 'source' => 'mobile-api'],
        ]);

        $audit->record('subscription.requested', $subscription, actor: $user);

        return $this->json([
            'subscription' => new SubscriptionResource($subscription),
            'pending' => true,
        ], 'Renewal request submitted.', SymfonyResponse::HTTP_CREATED);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Roles are created with the `web` guard; API auth switches the default
        // guard to `sanctum`, so resolve the role explicitly under `web`.
        $query = User::query()->role(Roles::SUBSCRIBERS, 'web')->with(['roles', 'permissions']);

        if (filled($filters['search'] ?? null)) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($query) => $query
                ->where('name', 'like', $term)
                ->orWhere('email', 'like', $term));
        }

        return UserResource::collection($query->orderBy('name')->paginate((int) ($filters['per_page'] ?? 15)))
            ->additional(['meta' => ['filters' => $filters]]);
    }

    public function requests(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', User::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'type' => ['nullable', Rule::enum(SubscriptionType::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'pending';

        $users = User::query()
            ->role(Roles::SUBSCRIBERS, 'web')
            ->whereHas('subscriptions', function ($query) use ($status, $filters): void {
                $query->where('status', $status);

                if (isset($filters['type'])) {
                    $query->where('type', $filters['type']);
                }
            })
            ->with(['roles', 'permissions'])
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 50));

        $data = $users->getCollection()->map(function (User $user) use ($request, $status): array {
            $subscription = $user->subscriptions()->where('status', $status)->latest()->first();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status?->value,
                'status_label' => $user->status?->label(),
                'roles' => $user->roles->pluck('name')->all(),
                'has_active_subscription' => $user->hasActiveSubscription(),
                'subscription' => (new SubscriptionResource($subscription))->resolve($request),
            ];
        })->all();

        return $this->json($data, meta: [
            'status' => $status,
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'per_page' => $users->perPage(),
            'total' => $users->total(),
        ]);
    }

    public function show(Request $request, User $user): UserResource
    {
        Gate::forUser($request->user())->authorize('viewAny', User::class);

        return (new UserResource($user->loadMissing(['roles', 'permissions'])))->additional([
            'meta' => [
                'history' => SubscriptionResource::collection(
                    $user->subscriptions()->latest()->limit(20)->get()
                )->resolve($request),
            ],
        ]);
    }

    public function activate(Request $request, User $user, SubscriptionService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', User::class);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(SubscriptionType::class)],
        ]);

        $subscription = $service->activate($user, SubscriptionType::from($validated['type']));

        return $this->json([
            'subscription' => new SubscriptionResource($subscription),
        ], 'Subscription activated.', SymfonyResponse::HTTP_CREATED);
    }

    public function approve(Request $request, User $user, SubscriptionService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', User::class);

        $pending = $user->subscriptions()->where('status', 'pending')->latest()->first();

        if ($pending === null) {
            throw ValidationException::withMessages([
                'subscription' => 'This administrator has no pending subscription request.',
            ]);
        }

        $subscription = $service->activate($user, $pending->type);

        return $this->json([
            'subscription' => new SubscriptionResource($subscription),
        ], 'Subscription request approved.', SymfonyResponse::HTTP_CREATED);
    }

    public function reject(Request $request, User $user, SubscriptionService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', User::class);

        $count = $service->reject($user);

        if ($count === 0) {
            throw ValidationException::withMessages([
                'subscription' => 'This administrator has no pending subscription request.',
            ]);
        }

        return $this->json([
            'rejected' => $count,
        ], 'Subscription request rejected.');
    }

    public function updatePrices(Request $request, SubscriptionPricingService $pricing, AuditLogger $audit): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', User::class);

        $validated = $request->validate([
            'monthly' => ['required', 'numeric', 'min:0'],
            'yearly' => ['required', 'numeric', 'min:0'],
        ]);

        $prices = [
            SubscriptionType::Monthly->value => (float) $validated['monthly'],
            SubscriptionType::Yearly->value => (float) $validated['yearly'],
        ];

        $pricing->updatePrices($prices, $request->user());
        $audit->record('subscription.prices_updated', actor: $request->user(), metadata: $prices);

        return $this->json($pricing->options(), 'Subscription type prices updated.');
    }

    public function deactivate(Request $request, User $user, SubscriptionService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', User::class);

        $count = $service->deactivate($user);

        return $this->json([
            'deactivated' => $count,
        ], 'Subscription deactivated.');
    }
}
