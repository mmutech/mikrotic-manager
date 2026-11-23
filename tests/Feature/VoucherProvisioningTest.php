<?php

namespace Tests\Feature;

use App\Enums\ProvisioningStatus;
use App\Enums\VoucherLifecycleStatus;
use App\Jobs\ProvisionVoucherJob;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;
use App\Services\AuditLogger;
use App\Services\RouterApiRecorder;
use App\Services\RouterConnectionFactory;
use App\Services\Vouchers\VoucherProvisioningService;
use App\Services\Vouchers\VoucherValiditySyncService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mivo\MikrotikRos7\Client;
use Mivo\MikrotikRos7\Exceptions\MikrotikException;
use Tests\TestCase;

class VoucherProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_a_pending_voucher_for_provisioning(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([Permissions::VOUCHERS_VIEW, Permissions::VOUCHERS_PROVISION]);
        $voucher = Voucher::factory()->create([
            'provisioning_status' => ProvisioningStatus::Pending,
        ]);
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::vouchers.show', ['voucher' => $voucher])
            ->call('provision')
            ->assertHasNoErrors();

        Bus::assertDispatched(ProvisionVoucherJob::class);
        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->getKey(),
            'provisioning_status' => ProvisioningStatus::Queued->value,
        ]);
    }

    public function test_it_rejects_provisioning_an_already_provisioned_voucher(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([Permissions::VOUCHERS_VIEW, Permissions::VOUCHERS_PROVISION]);
        $voucher = Voucher::factory()->create([
            'provisioning_status' => ProvisioningStatus::Provisioned,
        ]);
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::vouchers.show', ['voucher' => $voucher])
            ->call('provision');

        Bus::assertNotDispatched(ProvisionVoucherJob::class);
        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->getKey(),
            'provisioning_status' => ProvisioningStatus::Provisioned->value,
        ]);
    }

    public function test_it_does_not_send_already_provisioning_vouchers_to_the_router_again(): void
    {
        $voucher = Voucher::factory()->create([
            'provisioning_status' => ProvisioningStatus::Provisioning,
        ]);
        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->never())->method('make');

        $service = new VoucherProvisioningService(
            $connections,
            $this->createMock(RouterApiRecorder::class),
            $this->createMock(AuditLogger::class),
        );

        $service->provision($voucher);

        $this->assertSame(ProvisioningStatus::Provisioning, $voucher->fresh()->provisioning_status);
    }

    public function test_it_provisions_a_voucher_and_records_the_supplied_correlation_id(): void
    {
        $router = Router::factory()->create();
        $plan = Plan::factory()->create(['router_id' => $router->getKey()]);
        $voucher = Voucher::factory()->create([
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'provisioning_status' => ProvisioningStatus::Queued,
            'data_allowance_bytes' => 2048,
            'time_allowance_seconds' => 61,
        ]);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('put')
            ->with('/rest/ip/hotspot/user', [
                'name' => $voucher->username,
                'password' => $voucher->password,
                'profile' => $plan->code,
                'limit-bytes-total' => '2048',
                'limit-uptime' => '2m',
            ])
            ->willReturn([]);
        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())
            ->method('make')
            ->with($this->callback(fn (Router $connectionRouter): bool => $connectionRouter->is($router)))
            ->willReturn($client);
        $recorder = $this->createMock(RouterApiRecorder::class);
        $recorder->expects($this->once())
            ->method('success')
            ->with(
                $this->callback(fn (Router $recordedRouter): bool => $recordedRouter->is($router)),
                'PUT',
                '/rest/ip/hotspot/user',
                $this->isType('int'),
                200,
                ['name' => $voucher->username],
                ['profile' => $plan->code],
                'correlation-id',
            );
        $audit = app(AuditLogger::class);

        (new VoucherProvisioningService($connections, $recorder, $audit))
            ->provision($voucher, 'correlation-id');

        $this->assertSame(ProvisioningStatus::Provisioned, $voucher->fresh()->provisioning_status);
        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->getKey(),
            'lifecycle_status' => 'active',
            'provisioning_status' => 'provisioned',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'voucher.provisioned',
            'correlation_id' => 'correlation-id',
            'auditable_id' => $voucher->getKey(),
        ]);
    }

    public function test_it_starts_calendar_validity_when_a_provisioned_voucher_is_first_seen_active(): void
    {
        $router = Router::factory()->create(['monitoring_enabled' => false]);
        $voucher = $this->createProvisionedVoucher($router, [
            'validity_seconds' => 3600,
            'first_used_at' => null,
            'expires_at' => null,
        ]);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('get')
            ->with('/rest/ip/hotspot/active')
            ->willReturn([['user' => $voucher->username, '.id' => '*1']]);

        $this->createValiditySyncService($router, $client)->sync();

        $voucher = $voucher->fresh();
        $this->assertNotNull($voucher->first_used_at);
        $this->assertTrue(
            $voucher->expires_at->equalTo($voucher->first_used_at->copy()->addSeconds(3600))
        );
    }

    public function test_it_does_not_start_validity_until_the_voucher_is_seen_active(): void
    {
        $router = Router::factory()->create();
        $voucher = $this->createProvisionedVoucher($router, [
            'validity_seconds' => 3600,
            'first_used_at' => null,
            'expires_at' => null,
        ]);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('get')
            ->with('/rest/ip/hotspot/active')
            ->willReturn([]);
        $client->expects($this->never())->method('delete');

        $this->createValiditySyncService($router, $client)->sync();

        $this->assertNull($voucher->fresh()->first_used_at);
        $this->assertNull($voucher->fresh()->expires_at);
    }

    public function test_it_removes_expired_router_sessions_and_users_before_marking_voucher_removed(): void
    {
        $router = Router::factory()->create();
        $voucher = $this->createProvisionedVoucher($router, [
            'first_used_at' => now()->subHours(2),
            'expires_at' => now()->subMinute(),
        ]);
        $client = $this->createMock(Client::class);
        $deleteRequests = [];
        $client->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(function (string $endpoint) use (&$deleteRequests): array {
                $deleteRequests[] = $endpoint;

                return [];
            });
        $client->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $endpoint, array $params = []) use ($voucher): array {
                if ($endpoint === '/rest/ip/hotspot/active') {
                    return [['user' => $voucher->username, '.id' => '*1']];
                }

                return [['name' => $voucher->username, '.id' => '*5']];
            });

        $this->createValiditySyncService($router, $client)->sync();

        $this->assertSame([
            '/rest/ip/hotspot/active/*1',
            '/rest/ip/hotspot/user/*5',
        ], $deleteRequests);
        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->getKey(),
            'lifecycle_status' => VoucherLifecycleStatus::Expired->value,
            'provisioning_status' => ProvisioningStatus::Removed->value,
        ]);
    }

    public function test_it_keeps_expired_vouchers_provisioned_when_router_removal_fails(): void
    {
        $router = Router::factory()->create();
        $voucher = $this->createProvisionedVoucher($router, [
            'first_used_at' => now()->subHours(2),
            'expires_at' => now()->subMinute(),
        ]);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('get')
            ->with('/rest/ip/hotspot/active')
            ->willReturn([['user' => $voucher->username, '.id' => '*1']]);
        $client->expects($this->once())
            ->method('delete')
            ->with('/rest/ip/hotspot/active/*1')
            ->willThrowException(new MikrotikException('Router unavailable'));

        $this->createValiditySyncService($router, $client)->sync();

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->getKey(),
            'lifecycle_status' => VoucherLifecycleStatus::Active->value,
            'provisioning_status' => ProvisioningStatus::Provisioned->value,
        ]);
    }

    public function test_generated_vouchers_snapshot_plan_validity_settings(): void
    {
        $router = Router::factory()->create();
        $plan = Plan::factory()->create([
            'router_id' => $router->getKey(),
            'validity_seconds' => 7200,
            'validity_starts' => 'first_use',
            'expiry_policy' => 'calendar',
        ]);
        $user = User::factory()->create();
        $user->givePermissionTo(Permissions::VOUCHERS_GENERATE);
        $user->routers()->attach($router);

        $this->actingAs($user);

        Livewire::test('pages::vouchers.generate')
            ->set('router_id', (string) $router->getKey())
            ->set('plan_id', (string) $plan->getKey())
            ->set('quantity', 1)
            ->call('generate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('vouchers', [
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'validity_seconds' => 7200,
            'validity_starts' => 'first_use',
            'expiry_policy' => 'calendar',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createProvisionedVoucher(Router $router, array $attributes = []): Voucher
    {
        $plan = Plan::factory()->create(['router_id' => $router->getKey()]);

        return Voucher::factory()->create(array_merge([
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'lifecycle_status' => VoucherLifecycleStatus::Active,
            'provisioning_status' => ProvisioningStatus::Provisioned,
        ], $attributes));
    }

    private function createValiditySyncService(Router $router, Client $client): VoucherValiditySyncService
    {
        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())
            ->method('make')
            ->with($this->callback(fn (Router $connectionRouter): bool => $connectionRouter->is($router)))
            ->willReturn($client);

        $audit = $this->createMock(AuditLogger::class);
        $audit->method('record')->willReturn(new AuditLog);

        return new VoucherValiditySyncService(
            $connections,
            $this->createMock(RouterApiRecorder::class),
            $audit,
        );
    }
}
