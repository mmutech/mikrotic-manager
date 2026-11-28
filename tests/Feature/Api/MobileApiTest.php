<?php

namespace Tests\Feature\Api;

use App\Contracts\RouterGateway;
use App\Enums\TransactionStatus;
use App\Enums\UserStatus;
use App\Jobs\ProvisionVoucherJob;
use App\Jobs\RevokeVoucherJob;
use App\Jobs\TestRouterConnectionJob;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_me_and_logout_with_sanctum_token(): void
    {
        $user = User::factory()->create([
            'email' => 'api-user@example.test',
            'password' => Hash::make('password123'),
        ]);

        $login = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password123',
            'device_name' => 'phpunit',
        ])->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', $user->email);

        $token = $login->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);

        $this->withToken($token)
            ->postJson('/api/v1/logout')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_user_can_deactivate_own_account_and_all_of_its_tokens(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $other = User::factory()->create();

        $token = $user->createToken('phpunit', ['*'])->plainTextToken;
        $secondToken = $user->createToken('phpunit-second', ['*'])->plainTextToken;
        $otherToken = $other->createToken('phpunit', ['*'])->plainTextToken;

        $this->withToken($token)
            ->deleteJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('message', 'Account deactivated successfully.');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => UserStatus::Inactive->value,
        ]);
        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseHas('users', [
            'id' => $other->id,
            'status' => UserStatus::Active->value,
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.account_deactivated',
            'actor_type' => $user->getMorphClass(),
            'actor_id' => $user->id,
        ]);

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($secondToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($otherToken)->getJson('/api/v1/me')->assertOk();

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnprocessable();
    }

    public function test_inactive_account_is_rejected_and_its_token_is_removed(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => Hash::make('password123'),
        ]);
        $token = $user->createToken('phpunit', ['*'])->plainTextToken;

        $user->forceFill(['status' => 'inactive'])->save();

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('error', 'account_inactive');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_subscription_routes_work_before_subscription_but_operations_do_not(): void
    {
        config()->set([
            'mmutech.subscription.prices.monthly' => 1000,
            'mmutech.subscription.prices.yearly' => 10000,
        ]);

        $user = User::factory()->create();
        $user->assignRole('network-admin');
        $token = $user->createToken('phpunit', ['*'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.requires_subscription', true)
            ->assertJsonPath('data.has_active_subscription', false);

        $this->withToken($token)
            ->getJson('/api/v1/subscription/plans')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.value', 'monthly')
            ->assertJsonPath('data.0.price', 1000)
            ->assertJsonPath('data.1.value', 'yearly')
            ->assertJsonPath('data.1.price', 10000);

        $this->withToken($token)
            ->postJson('/api/v1/subscription/request', ['type' => 'yearly'])
            ->assertCreated()
            ->assertJsonPath('data.subscription.status', 'pending')
            ->assertJsonPath('data.subscription.type', 'yearly')
            ->assertJsonPath('data.subscription.type_label', 'Yearly')
            ->assertJsonPath('data.subscription.duration_days', 365)
            ->assertJsonPath('data.subscription.price', '10000.00')
            ->assertJsonPath('data.subscription.currency', 'NGN');

        $this->withToken($token)
            ->getJson('/api/v1/routers')
            ->assertForbidden()
            ->assertJsonPath('error', 'subscription_inactive');

        $this->grantSubscription($user);

        $this->withToken($token)
            ->getJson('/api/v1/routers')
            ->assertOk();
    }

    public function test_admin_can_view_approve_and_reject_subscription_requests(): void
    {
        config()->set([
            'mmutech.subscription.prices.monthly' => 1200,
            'mmutech.subscription.prices.yearly' => 12000,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $token = $admin->createToken('phpunit', ['*'])->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/subscriptions/prices', ['monthly' => 1500, 'yearly' => 15000])
            ->assertOk()
            ->assertJsonPath('data.0.price', 1500)
            ->assertJsonPath('data.1.price', 15000);

        $approveUser = User::factory()->create(['name' => 'A Requester']);
        $approveUser->assignRole('network-admin');
        $pendingApprove = Subscription::factory()->pending()->create(['user_id' => $approveUser->id]);

        $rejectUser = User::factory()->create(['name' => 'B Requester']);
        $rejectUser->assignRole('network-admin');
        $pendingReject = Subscription::factory()->pending()->create(['user_id' => $rejectUser->id]);

        $salesRequester = User::factory()->create(['name' => 'D Sales Requester']);
        $salesRequester->assignRole('sales-agent');
        $pendingSales = Subscription::factory()->pending()->yearly()->create(['user_id' => $salesRequester->id]);

        $approvedUser = User::factory()->create(['name' => 'C Approved']);
        $approvedUser->assignRole('network-admin');
        Subscription::factory()->create([
            'user_id' => $approvedUser->id,
            'status' => 'approved',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/subscriptions/requests')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment(['email' => $approveUser->email])
            ->assertJsonFragment(['email' => $rejectUser->email])
            ->assertJsonFragment(['email' => $salesRequester->email]);

        $this->withToken($token)
            ->getJson('/api/v1/subscriptions/requests?type=yearly')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', $salesRequester->email);

        $this->withToken($token)
            ->getJson('/api/v1/subscriptions/requests?status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', $approvedUser->email);

        $this->withToken($token)
            ->postJson("/api/v1/subscriptions/{$approveUser->id}/approve")
            ->assertCreated()
            ->assertJsonPath('data.subscription.status', 'active');

        $this->assertSame('approved', $pendingApprove->fresh()->status);
        $this->assertTrue($approveUser->fresh()->hasActiveSubscription());

        $this->withToken($token)
            ->postJson("/api/v1/subscriptions/{$salesRequester->id}/approve")
            ->assertCreated()
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.type', 'yearly')
            ->assertJsonPath('data.subscription.duration_days', 365)
            ->assertJsonPath('data.subscription.price', '15000.00');

        $this->assertSame('approved', $pendingSales->fresh()->status);
        $this->assertTrue($salesRequester->fresh()->hasActiveSubscription());

        $this->withToken($token)
            ->postJson("/api/v1/subscriptions/{$rejectUser->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.rejected', 1);

        $this->assertSame('rejected', $pendingReject->fresh()->status);

        $this->withToken($token)
            ->getJson('/api/v1/subscriptions/requests')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $activatedUser = User::factory()->create(['name' => 'D Activated']);
        $activatedUser->assignRole('network-admin');

        $this->withToken($token)
            ->postJson("/api/v1/subscriptions/{$activatedUser->id}/activate", [
                'type' => 'yearly',
            ])
            ->assertCreated()
            ->assertJsonPath('data.subscription.type', 'yearly')
            ->assertJsonPath('data.subscription.plan', 'yearly')
            ->assertJsonPath('data.subscription.duration_days', 365)
            ->assertJsonPath('data.subscription.price', '15000.00');

        $networkAdmin = User::factory()->create();
        $networkAdmin->assignRole('network-admin');
        $networkToken = $networkAdmin->createToken('phpunit', ['*'])->plainTextToken;

        // Sanctum caches the resolved user on the guard between test requests.
        Auth::guard('sanctum')->forgetUser();

        $this->withToken($networkToken)
            ->getJson('/api/v1/subscriptions/requests')
            ->assertForbidden();

        $this->withToken($networkToken)
            ->putJson('/api/v1/subscriptions/prices', ['monthly' => 1, 'yearly' => 1])
            ->assertForbidden();
    }

    public function test_network_admin_manages_only_assigned_routers_and_own_subscription(): void
    {
        Queue::fake();

        $networkAdmin = User::factory()->create();
        $networkAdmin->assignRole('network-admin');
        $this->grantSubscription($networkAdmin);

        $assignedRouter = Router::factory()->create();
        $otherRouter = Router::factory()->create();
        $networkAdmin->routers()->attach($assignedRouter);

        $token = $networkAdmin->createToken('phpunit', ['*'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.requires_subscription', true)
            ->assertJsonPath('data.has_active_subscription', true);

        $this->withToken($token)
            ->getJson('/api/v1/routers')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($token)
            ->getJson('/api/v1/routers/'.$assignedRouter->public_id)
            ->assertOk()
            ->assertJsonPath('meta.can_test', true);

        $this->withToken($token)
            ->getJson('/api/v1/routers/'.$otherRouter->public_id)
            ->assertForbidden();

        $this->withToken($token)
            ->postJson('/api/v1/routers/'.$assignedRouter->public_id.'/test')
            ->assertAccepted();

        Queue::assertPushed(TestRouterConnectionJob::class, 1);

        $this->withToken($token)
            ->postJson('/api/v1/routers/'.$otherRouter->public_id.'/test')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/v1/reports')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/subscriptions/requests')
            ->assertForbidden();

        $target = User::factory()->create();
        $target->assignRole('network-admin');

        $this->withToken($token)
            ->postJson('/api/v1/subscriptions/'.$target->id.'/activate', [
                'days' => 30,
                'amount' => 0,
                'plan' => 'basic',
            ])
            ->assertForbidden();
    }

    public function test_sales_agent_requires_subscription_and_only_runs_the_voucher_workflow(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $agent->assignRole('sales-agent');
        $token = $agent->createToken('phpunit', ['*'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.requires_subscription', true)
            ->assertJsonPath('data.has_active_subscription', false);

        $this->withToken($token)
            ->getJson('/api/v1/routers')
            ->assertForbidden()
            ->assertJsonPath('error', 'subscription_inactive');

        $this->grantSubscription($agent);

        $assignedRouter = Router::factory()->create();
        $otherRouter = Router::factory()->create();
        $agent->routers()->attach($assignedRouter);

        $plan = Plan::factory()->create([
            'router_id' => $assignedRouter->id,
            'price' => 1500,
            'currency' => 'NGN',
            'is_active' => true,
        ]);
        $plan->profileMappings()->create([
            'router_id' => $assignedRouter->id,
            'hotspot_profile' => 'default',
        ]);
        $otherPlan = Plan::factory()->create([
            'router_id' => $otherRouter->id,
            'price' => 9000,
            'currency' => 'NGN',
        ]);
        $otherVoucher = Voucher::factory()->create([
            'router_id' => $otherRouter->id,
            'plan_id' => $otherPlan->id,
        ]);

        $gateway = $this->mock(RouterGateway::class);
        $gateway->shouldReceive('activeHotspotSessions')->andReturn([]);
        $gateway->shouldReceive('hotspotUserProfiles')->andReturn([[
            'id' => '*1',
            'name' => 'default',
            'rate_limit' => '2M/4M',
            'shared_users' => '1',
        ]]);

        $this->withToken($token)
            ->getJson('/api/v1/routers')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($token)
            ->getJson('/api/v1/routers/'.$assignedRouter->public_id)
            ->assertOk()
            ->assertJsonPath('meta.can_test', false);

        $this->withToken($token)
            ->getJson('/api/v1/routers/'.$otherRouter->public_id)
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/v1/routers/'.$assignedRouter->public_id.'/hotspot-profiles')
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/routers/'.$assignedRouter->public_id.'/test')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($token)
            ->getJson('/api/v1/plans/'.$plan->public_id)
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/plans/'.$otherPlan->public_id)
            ->assertForbidden();

        $this->withToken($token)
            ->postJson('/api/v1/plans', [
                'router_id' => $assignedRouter->public_id,
                'name' => 'Not allowed',
                'hotspot_profile' => 'default',
                'price' => 0,
                'currency' => 'NGN',
                'is_unlimited' => true,
                'time_allowance_seconds' => 0,
                'is_active' => true,
            ])
            ->assertForbidden();

        $generated = $this->withToken($token)
            ->postJson('/api/v1/vouchers/generate', [
                'router_id' => $assignedRouter->public_id,
                'plan_id' => $plan->public_id,
                'quantity' => 2,
                'prefix' => 'AGENT',
            ])
            ->assertCreated();

        $voucher = $generated->json('data.0');

        $this->withToken($token)
            ->getJson('/api/v1/vouchers')
            ->assertOk()
            ->assertJsonFragment(['public_id' => $voucher['public_id']])
            ->assertJsonMissing(['public_id' => $otherVoucher->public_id]);

        Voucher::factory()->create([
            'router_id' => $assignedRouter->id,
            'plan_id' => $plan->id,
            'provisioning_status' => 'provisioned',
            'lifecycle_status' => 'active',
        ]);
        Voucher::factory()->create([
            'router_id' => $assignedRouter->id,
            'plan_id' => $plan->id,
            'provisioning_status' => 'provisioned',
            'lifecycle_status' => 'active',
        ]);
        Voucher::factory()->create([
            'router_id' => $otherRouter->id,
            'plan_id' => $otherPlan->id,
            'provisioning_status' => 'provisioned',
            'lifecycle_status' => 'active',
        ]);
        Voucher::factory()->create([
            'router_id' => $assignedRouter->id,
            'plan_id' => $plan->id,
            'provisioning_status' => 'failed',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/vouchers/stats')
            ->assertOk()
            ->assertJsonPath('data.period', 'today')
            ->assertJsonPath('data.total', 5)
            ->assertJsonPath('data.revenue.period', 'today')
            ->assertJsonPath('data.revenue.total_amount', 3000)
            ->assertJsonPath('data.revenue.voucher_count', 2)
            ->assertJsonPath('data.revenue.by_currency.0.currency', 'NGN')
            ->assertJsonPath('data.revenue.by_currency.0.amount', 3000);

        $this->withToken($token)
            ->getJson('/api/v1/vouchers/'.$voucher['public_id'])
            ->assertOk()
            ->assertJsonPath('data.actions.print', true)
            ->assertJsonPath('data.actions.provision', true)
            ->assertJsonPath('data.actions.revoke', true);

        $this->withToken($token)
            ->getJson('/api/v1/vouchers/'.$otherVoucher->public_id)
            ->assertForbidden();

        $this->withToken($token)
            ->postJson('/api/v1/vouchers/'.$voucher['public_id'].'/provision')
            ->assertOk()
            ->assertJsonPath('message', 'Provisioning queued.');

        Queue::assertPushed(ProvisionVoucherJob::class, 1);

        $this->withToken($token)
            ->postJson('/api/v1/vouchers/'.$voucher['public_id'].'/revoke')
            ->assertOk()
            ->assertJsonPath('message', 'Revocation queued.');

        Queue::assertPushed(RevokeVoucherJob::class, 1);

        $this->withToken($token)
            ->getJson('/api/v1/reports')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/v1/router-status')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/v1/subscriptions/requests')
            ->assertForbidden();
    }

    public function test_voucher_stats_period_filters_vouchers_and_revenue(): void
    {
        $this->travelTo(Carbon::parse('2026-11-28 12:00:00'));

        $agent = User::factory()->create();
        $agent->assignRole('sales-agent');
        $this->grantSubscription($agent);

        $router = Router::factory()->create();
        $agent->routers()->attach($router);

        $plan = Plan::factory()->create([
            'router_id' => $router->id,
            'price' => 1500,
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        foreach (['2026-11-28 09:00:00', '2026-11-25 09:00:00', '2026-11-05 09:00:00', '2026-10-01 09:00:00'] as $date) {
            Voucher::factory()->create([
                'router_id' => $router->id,
                'plan_id' => $plan->id,
                'provisioning_status' => 'provisioned',
                'lifecycle_status' => 'active',
                'created_at' => Carbon::parse($date),
            ]);
        }

        Voucher::factory()->create([
            'router_id' => $router->id,
            'plan_id' => $plan->id,
            'provisioning_status' => 'failed',
            'created_at' => Carbon::parse('2026-11-28 10:00:00'),
        ]);

        $token = $agent->createToken('phpunit', ['*'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/vouchers/stats?period=today')
            ->assertOk()
            ->assertJsonPath('data.period', 'today')
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.revenue.total_amount', 1500)
            ->assertJsonPath('data.revenue.voucher_count', 1);

        $this->withToken($token)
            ->getJson('/api/v1/vouchers/stats?period=week')
            ->assertOk()
            ->assertJsonPath('data.period', 'week')
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.revenue.total_amount', 3000)
            ->assertJsonPath('data.revenue.voucher_count', 2);

        $this->withToken($token)
            ->getJson('/api/v1/vouchers/stats?period=month')
            ->assertOk()
            ->assertJsonPath('data.period', 'month')
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.revenue.total_amount', 4500)
            ->assertJsonPath('data.revenue.voucher_count', 3);

        $this->withToken($token)
            ->getJson('/api/v1/vouchers/stats?period=all')
            ->assertOk()
            ->assertJsonPath('data.period', 'all')
            ->assertJsonPath('data.total', 5)
            ->assertJsonPath('data.revenue.total_amount', 6000)
            ->assertJsonPath('data.revenue.voucher_count', 4);

        $this->travelBack();
    }

    public function test_voucher_generation_show_live_usage_and_unsupported_fields(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $router = Router::factory()->create();
        $plan = Plan::factory()->create([
            'router_id' => $router->id,
            'time_allowance_seconds' => 0,
        ]);
        $plan->profileMappings()->create([
            'router_id' => $router->id,
            'hotspot_profile' => 'default',
        ]);

        $token = $user->createToken('phpunit', ['*'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/vouchers/generate', [
            'router_id' => $router->public_id,
            'plan_id' => $plan->public_id,
            'quantity' => 2,
            'prefix' => 'TEST',
        ])->assertCreated();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.time_allowance_seconds', 0);

        $username = $response->json('data.0.username');
        $publicId = $response->json('data.0.public_id');

        $this->assertStringStartsWith('TEST-', $username);
        $this->assertDatabaseCount('vouchers', 2);

        $this->withToken($token)
            ->getJson('/api/v1/plans/'.$plan->public_id)
            ->assertOk()
            ->assertJsonPath('data.connected_time_label', 'No maximum time limit');

        $this->mock(RouterGateway::class)
            ->shouldReceive('activeHotspotSessions')
            ->twice()
            ->andReturn([[
                'username' => $username,
                'connected_seconds' => 65,
                'total_bytes' => 2048,
            ]]);

        $this->withToken($token)
            ->getJson('/api/v1/vouchers/'.$publicId)
            ->assertOk()
            ->assertJsonPath('data.username', $username)
            ->assertJsonPath('data.connected_time', '1m 5s')
            ->assertJsonPath('data.consumption', '2.0 KB')
            ->assertJsonPath('data.live_usage.status', 'connected');

        $this->withToken($token)
            ->postJson('/api/v1/vouchers/'.$publicId.'/provision')
            ->assertOk()
            ->assertJsonPath('message', 'Provisioning queued.');

        $this->assertDatabaseHas('vouchers', [
            'id' => $response->json('data.0.id'),
            'provisioning_status' => 'queued',
        ]);

        $this->withToken($token)->postJson('/api/v1/vouchers/generate', [
            'router_id' => $router->public_id,
            'plan_id' => $plan->public_id,
            'quantity' => 1,
            'login_address' => '10.0.0.1',
        ])->assertStatus(422)->assertJsonValidationErrors('login_address');

        $this->withToken($token)->postJson('/api/v1/vouchers/generate', [
            'router_id' => $router->public_id,
            'plan_id' => $plan->public_id,
            'quantity' => 1,
            'connected_time_allowance' => 3600,
        ])->assertStatus(422)->assertJsonValidationErrors('connected_time_allowance');
    }

    public function test_bulk_provision_and_revoke_accept_public_ids_and_queue_tracked_jobs(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $token = $user->createToken('phpunit', ['*'])->plainTextToken;

        $router = Router::factory()->create();
        $plan = Plan::factory()->create(['router_id' => $router->id]);

        $pending = Voucher::factory()->count(2)->create([
            'router_id' => $router->id,
            'plan_id' => $plan->id,
            'lifecycle_status' => 'pending',
            'provisioning_status' => 'pending',
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/vouchers/provision', [
                'voucher_ids' => $pending->pluck('public_id')->all(),
            ])
            ->assertAccepted()
            ->assertJsonPath('data.requested', 2)
            ->assertJsonPath('data.queued', 2)
            ->assertJsonPath('data.skipped', 0);

        Queue::assertPushed(ProvisionVoucherJob::class, 2);

        Queue::fake();

        $provisioned = Voucher::factory()->count(2)->create([
            'router_id' => $router->id,
            'plan_id' => $plan->id,
            'lifecycle_status' => 'active',
            'provisioning_status' => 'provisioned',
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/vouchers/revoke', [
                'voucher_ids' => $provisioned->pluck('public_id')->all(),
            ])
            ->assertAccepted()
            ->assertJsonPath('data.requested', 2)
            ->assertJsonPath('data.queued', 2)
            ->assertJsonPath('data.skipped', 0);

        Queue::assertPushed(RevokeVoucherJob::class, 2);
    }

    public function test_non_admin_sees_only_assigned_router_vouchers(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $networkAdmin = User::factory()->create();
        $networkAdmin->assignRole('network-admin');
        $this->grantSubscription($networkAdmin);

        $assignedRouter = Router::factory()->create();
        $otherRouter = Router::factory()->create();
        $networkAdmin->routers()->attach($assignedRouter);

        $assignedPlan = Plan::factory()->create(['router_id' => $assignedRouter->id]);
        $otherPlan = Plan::factory()->create(['router_id' => $otherRouter->id]);

        $assignedVoucher = Voucher::factory()->create([
            'router_id' => $assignedRouter->id,
            'plan_id' => $assignedPlan->id,
        ]);
        $otherVoucher = Voucher::factory()->create([
            'router_id' => $otherRouter->id,
            'plan_id' => $otherPlan->id,
        ]);

        $this->mock(RouterGateway::class)
            ->shouldReceive('activeHotspotSessions')
            ->andReturn([]);

        $token = $networkAdmin->createToken('phpunit', ['*'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/vouchers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $assignedVoucher->public_id);

        $this->withToken($token)
            ->getJson('/api/v1/vouchers/'.$otherVoucher->public_id)
            ->assertForbidden();

        $this->assertNotSame($assignedVoucher->id, $otherVoucher->id);
        $this->assertTrue($admin->isSuperAdmin());
    }

    public function test_hotspot_profile_discovery_does_not_queue_router_jobs_but_test_does(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $router = Router::factory()->create();
        $token = $user->createToken('phpunit', ['*'])->plainTextToken;

        $this->mock(RouterGateway::class)
            ->shouldReceive('hotspotUserProfiles')
            ->once()
            ->andReturn([[
                'id' => '*1',
                'name' => 'profile-1',
                'rate_limit' => '2M/4M',
                'shared_users' => '1',
            ]]);

        $this->withToken($token)
            ->getJson('/api/v1/routers/'.$router->public_id.'/hotspot-profiles')
            ->assertOk()
            ->assertJsonPath('data.profiles.0.name', 'profile-1')
            ->assertJsonPath('data.profiles.0.upload_speed_kbps', 2000)
            ->assertJsonPath('data.profiles.0.download_speed_kbps', 4000);

        Queue::assertNothingPushed();

        $this->withToken($token)
            ->postJson('/api/v1/routers/'.$router->public_id.'/test')
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued');

        $this->assertDatabaseHas('router_jobs', [
            'router_id' => $router->id,
            'type' => 'test_connection',
            'status' => 'queued',
        ]);
    }

    public function test_revenue_reports_only_paid_transactions(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $router = Router::factory()->create();
        $token = $user->createToken('phpunit', ['*'])->plainTextToken;

        Transaction::factory()->create([
            'router_id' => $router->id,
            'status' => TransactionStatus::Paid->value,
            'amount' => 1500,
            'currency' => 'NGN',
            'paid_at' => now(),
        ]);
        Transaction::factory()->create([
            'router_id' => $router->id,
            'status' => TransactionStatus::Pending,
            'amount' => 9000,
            'currency' => 'NGN',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/reports/revenue')
            ->assertOk()
            ->assertJsonPath('data.transaction_count', 1)
            ->assertJsonPath('data.total_amount', 1500);
    }
}
