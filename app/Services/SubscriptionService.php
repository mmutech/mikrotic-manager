<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;

class SubscriptionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function activate(User $user, int $days = 30, string $plan = 'basic', float $amount = 0, string $currency = 'NGN'): Subscription
    {
        $actor = auth()->user();

        if (! $actor?->isSuperAdmin()) {
            throw new \RuntimeException('Only super administrators can activate subscriptions.');
        }

        $days = max(1, min(365, $days));
        $current = $user->activeSubscription();
        $startsAt = $current?->expires_at?->isFuture() ? $current->expires_at->copy() : Carbon::now();

        $user->subscriptions()
            ->where('status', 'active')
            ->update(['status' => 'expired']);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => $plan,
            'status' => 'active',
            'starts_at' => $startsAt,
            'expires_at' => $startsAt->copy()->addDays($days),
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => ['activated_by' => $actor->id],
        ]);

        $user->subscriptions()
            ->where('status', 'pending')
            ->update(['status' => 'approved']);

        $this->audit->record('subscription.activated', $subscription, after: $subscription->toArray(), actor: $actor);

        return $subscription;
    }

    public function deactivate(User $user): int
    {
        $actor = auth()->user();

        if (! $actor?->isSuperAdmin()) {
            throw new \RuntimeException('Only super administrators can deactivate subscriptions.');
        }

        $count = $user->subscriptions()->where('status', 'active')->update(['status' => 'expired']);

        if ($count > 0) {
            $this->audit->record('subscription.deactivated', $user, metadata: ['count' => $count], actor: $actor);
        }

        return $count;
    }
}
