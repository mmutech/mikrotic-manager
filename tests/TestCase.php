<?php

namespace Tests;

use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Give a user an active subscription so subscription-gated routes remain reachable.
     */
    protected function grantSubscription(User $user, int $days = 30): Subscription
    {
        return Subscription::factory()->create([
            'user_id' => $user->id,
            'expires_at' => now()->addDays($days),
        ]);
    }
}
