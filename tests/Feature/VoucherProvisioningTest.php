<?php

namespace Tests\Feature;

use App\Contracts\RouterGateway;
use App\Enums\ProvisioningStatus;
use App\Enums\VoucherLifecycleStatus;
use App\Exceptions\RouterProbeException;
use App\Jobs\ProvisionVoucherJob;
use App\Jobs\RevokeVoucherJob;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Router;
use App\Models\RouterPlanProfile;
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
use RuntimeException;
use Tests\TestCase;

class VoucherProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_a_pending_voucher_for_provisioning(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([Permissions::VOUCHERS_VIEW, Permissions::VOUCHERS_PROVISION]);
        $router = Router::factory()->create();
        $router->users()->attach($user);
        $voucher = Voucher::factory()->create([
            'router_id' => $router->getKey(),
            'provisioning_status' => ProvisioningStatus::Pending,
        ]);
        Bus::fake();

        $this->actingAs($user);

        $component = Livewire::test('pages::vouchers.show', ['voucher' => $voucher])
            ->call('openProvisioningDialog')
            ->assertSet('provisioningDialog', 'confirm')
            ->call('provision')
            ->assertHasNoErrors()
            ->assertSet('provisioningDialog', 'processing')
            ->assertSee('Provisioning is queued.');

        Bus::assertDispatched(ProvisionVoucherJob::class);
        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->getKey(),
            'provisioning_status' => ProvisioningStatus::Queued->value,
        ]);

        $voucher->update(['provisioning_status' => ProvisioningStatus::Provisioned]);
        $component->call('refreshProvisioningDialog')
            ->assertSet('provisioningDialog', 'success')
            ->assertSee('Provisioning completed successfully.');
    }

    public function test_it_shows_a_printable_username_only_voucher_to_users_with_print_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([Permissions::VOUCHERS_VIEW, Permissions::VOUCHERS_PRINT]);
        $router = Router::factory()->create();
        $router->users()->attach($user);
        $voucher = Voucher::factory()->create([
            'router_id' => $router->getKey(),
            'provisioning_status' => ProvisioningStatus::Provisioned,
        ]);

        $this->actingAs($user);

        $this->get(route('vouchers.show', $voucher))
            ->assertOk()
            ->assertSee('window.printVoucherContent', false)
            ->assertSee('frame.srcdoc', false);

        Livewire::test('pages::vouchers.show', ['voucher' => $voucher])
            ->assertSee('Print voucher')
            ->assertSee('Enter this code to access the internet')
            ->assertSee($voucher->username);
    }

    public function test_it_prints_multiple_selected_vouchers_on_one_print_sheet(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $user->givePermissionTo([Permissions::VOUCHERS_VIEW, Permissions::VOUCHERS_PRINT]);
        $firstVoucher = Voucher::factory()->create([
            'provisioning_status' => ProvisioningStatus::Provisioned,
        ]);
        $secondVoucher = Voucher::factory()->create([
            'provisioning_status' => ProvisioningStatus::Provisioned,
        ]);

        $this->actingAs($user);

        Livewire::test('pages::vouchers.index')
            ->set('selectedVouchers', [$firstVoucher->getKey(), $secondVoucher->getKey()])
            ->call('printSelected')
            ->assertHasNoErrors()
            ->assertDispatched('print-vouchers')
            ->assertSee($firstVoucher->username)
            ->assertSee($secondVoucher->username);
    }

    public function test_it_rejects_printing_unprovisioned_vouchers(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $user->givePermissionTo([Permissions::VOUCHERS_VIEW, Permissions::VOUCHERS_PRINT]);
        $voucher = Voucher::factory()->create([
            'provisioning_status' => ProvisioningStatus::Pending,
        ]);

        $this->actingAs($user);

        Livewire::test('pages::vouchers.index')
            ->set('selectedVouchers', [$voucher->getKey()])
            ->call('printSelected')
            ->assertHasErrors('selectedVouchers');
    }

    public function test_it_queues_multiple_pending_and_failed_vouchers_for_provisioning(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $user->givePermissionTo([Permissions::VOUCHERS_VIEW, Permissions::VOUCHERS_PROVISION]);
        $router = Router::factory()->create();
        $plan = Plan::factory()->create(['router_id' => $router->getKey()]);
        $pendingVoucher = Voucher::factory()->create([
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'provisioning_status' => ProvisioningStatus::Pending,
        ]);
        $failedVoucher = Voucher::factory()->create([
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'provisioning_status' => ProvisioningStatus::Failed,
        ]);
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::vouchers.index')
            ->set('selectedVouchers', [$pendingVoucher->getKey(), $failedVoucher->getKey()])
            ->call('openProvisioningConfirmation')
            ->assertSet('provisioningModalState', 'confirm')
            ->call('provisionSelected')
            ->assertHasNoErrors()
            ->assertSet('provisioningModalState', 'alert')
            ->assertSee('Queued 2 voucher(s) for provisioning.');

        Bus::assertDispatchedTimes(ProvisionVoucherJob::class, 2);
        $this->assertSame(ProvisioningStatus::Queued, $pendingVoucher->fresh()->provisioning_status);
        $this->assertSame(ProvisioningStatus::Queued, $failedVoucher->fresh()->provisioning_status);
    }

    public function test_bulk_provisioning_rejects_a_selected_voucher_that_is_not_eligible(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $user->givePermissionTo([Permissions::VOUCHERS_VIEW, Permissions::VOUCHERS_PROVISION]);
        $pendingVoucher = Voucher::factory()->create([
            'provisioning_status' => ProvisioningStatus::Pending,
        ]);
        $provisionedVoucher = Voucher::factory()->create([
            'provisioning_status' => ProvisioningStatus::Provisioned,
        ]);
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::vouchers.index')
            ->set('selectedVouchers', [$pendingVoucher->getKey(), $provisionedVoucher->getKey()])
            ->call('provisionSelected')
            ->assertHasErrors('selectedVouchers');

        Bus::assertNotDispatched(ProvisionVoucherJob::class);
        $this->assertSame(ProvisioningStatus::Pending, $pendingVoucher->fresh()->provisioning_status);
    }

    public function test_it_rejects_provisioning_an_already_provisioned_voucher(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([Permissions::VOUCHERS_VIEW, Permissions::VOUCHERS_PROVISION]);
        $router = Router::factory()->create();
        $router->users()->attach($user);
        $voucher = Voucher::factory()->create([
            'router_id' => $router->getKey(),
            'provisioning_status' => ProvisioningStatus::Provisioned,
        ]);
        Bus::fake();

        $this->actingAs($user);

        Livewire::test('pages::vouchers.show', ['voucher' => $voucher])
            ->call('provision')
            ->assertSet('provisioningDialog', 'alert')
            ->assertSee('Only active or pending vouchers that have not expired or been exhausted can be provisioned.');

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
            $this->createMock(RouterGateway::class),
        );

        $service->provision($voucher);

        $this->assertSame(ProvisioningStatus::Provisioning, $voucher->fresh()->provisioning_status);
    }

    public function test_it_provisions_a_voucher_and_records_the_supplied_correlation_id(): void
    {
        $router = Router::factory()->create();
        $plan = Plan::factory()->create([
            'router_id' => $router->getKey(),
            'upload_speed_kbps' => 512,
            'download_speed_kbps' => 2000,
        ]);
        RouterPlanProfile::query()->create([
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'hotspot_profile' => 'daily-access',
        ]);
        $voucher = Voucher::factory()->create([
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'hotspot_profile' => 'daily-access',
            'provisioning_status' => ProvisioningStatus::Queued,
            'data_allowance_bytes' => 2048,
            'time_allowance_seconds' => 61,
            'upload_speed_kbps' => 512,
            'download_speed_kbps' => 2000,
        ]);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('put')
            ->with('/rest/ip/hotspot/user', [
                'name' => $voucher->username,
                'password' => $voucher->username,
                'profile' => 'daily-access',
                'limit-bytes-total' => '2048',
                'limit-uptime' => '61s',
            ])
            ->willReturn([]);
        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())
            ->method('make')
            ->with($this->callback(fn (Router $connectionRouter): bool => $connectionRouter->is($router)))
            ->willReturn($client);
        $recorder = $this->createMock(RouterApiRecorder::class);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('hotspotUserProfiles')
            ->with(
                $this->callback(fn (Router $profileRouter): bool => $profileRouter->is($router)),
                'correlation-id',
            )
            ->willReturn([
                ['name' => 'daily-access', 'rate_limit' => '512k/2M', 'shared_users' => '1'],
            ]);
        $recorder->expects($this->once())
            ->method('success')
            ->with(
                $this->callback(fn (Router $recordedRouter): bool => $recordedRouter->is($router)),
                'PUT',
                '/rest/ip/hotspot/user',
                $this->isType('int'),
                200,
                ['name' => $voucher->username],
                ['profile' => 'daily-access'],
                'correlation-id',
            );
        $audit = app(AuditLogger::class);

        (new VoucherProvisioningService($connections, $recorder, $audit, $gateway))
            ->provision($voucher, 'correlation-id');

        $this->assertSame(ProvisioningStatus::Provisioned, $voucher->fresh()->provisioning_status);
        $this->assertSame($voucher->username, $voucher->fresh()->plainPassword());
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

    public function test_it_backfills_legacy_speed_snapshots_from_the_mapped_router_profile(): void
    {
        $router = Router::factory()->create();
        $plan = Plan::factory()->create(['router_id' => $router->getKey()]);
        RouterPlanProfile::query()->create([
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'hotspot_profile' => 'legacy-access',
        ]);
        $voucher = Voucher::factory()->create([
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'hotspot_profile' => 'legacy-access',
            'provisioning_status' => ProvisioningStatus::Queued,
            'upload_speed_kbps' => null,
            'download_speed_kbps' => null,
            'data_allowance_bytes' => null,
            'time_allowance_seconds' => null,
        ]);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('put')
            ->with('/rest/ip/hotspot/user', [
                'name' => $voucher->username,
                'password' => $voucher->username,
                'profile' => 'legacy-access',
            ])
            ->willReturn([]);
        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->willReturn($client);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('hotspotUserProfiles')
            ->willReturn([
                ['name' => 'legacy-access', 'rate_limit' => '512k/2M', 'shared_users' => '1'],
            ]);

        (new VoucherProvisioningService(
            $connections,
            $this->createMock(RouterApiRecorder::class),
            app(AuditLogger::class),
            $gateway,
        ))->provision($voucher);

        $voucher = $voucher->fresh();
        $this->assertSame(512, $voucher->upload_speed_kbps);
        $this->assertSame(2000, $voucher->download_speed_kbps);
        $this->assertSame(ProvisioningStatus::Provisioned, $voucher->provisioning_status);
    }

    public function test_it_fails_provisioning_when_the_mapped_router_profile_is_missing(): void
    {
        $router = Router::factory()->create();
        $plan = Plan::factory()->create(['router_id' => $router->getKey()]);
        RouterPlanProfile::query()->create([
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'hotspot_profile' => 'missing-profile',
        ]);
        $voucher = Voucher::factory()->create([
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'hotspot_profile' => 'missing-profile',
            'provisioning_status' => ProvisioningStatus::Queued,
        ]);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('hotspotUserProfiles')
            ->willReturn([]);
        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->never())->method('make');

        $service = new VoucherProvisioningService(
            $connections,
            $this->createMock(RouterApiRecorder::class),
            app(AuditLogger::class),
            $gateway,
        );

        try {
            $service->provision($voucher);
            $this->fail('Provisioning should fail when the mapped router profile is missing.');
        } catch (RouterProbeException $exception) {
            $this->assertStringContainsString('does not exist', $exception->getMessage());
        }

        $this->assertSame(ProvisioningStatus::Failed, $voucher->fresh()->provisioning_status);
    }

    public function test_it_rejects_a_mapped_profile_with_different_rate_limits(): void
    {
        $router = Router::factory()->create();
        $plan = Plan::factory()->create([
            'router_id' => $router->getKey(),
            'upload_speed_kbps' => 512,
            'download_speed_kbps' => 2048,
        ]);
        $voucher = Voucher::factory()->create([
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'hotspot_profile' => 'limited-access',
            'provisioning_status' => ProvisioningStatus::Queued,
            'upload_speed_kbps' => 512,
            'download_speed_kbps' => 2048,
        ]);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->once())
            ->method('hotspotUserProfiles')
            ->willReturn([
                ['name' => 'limited-access', 'rate_limit' => '256k/1024k', 'shared_users' => '1'],
            ]);
        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->never())->method('make');

        $service = new VoucherProvisioningService(
            $connections,
            $this->createMock(RouterApiRecorder::class),
            app(AuditLogger::class),
            $gateway,
        );

        try {
            $service->provision($voucher);
            $this->fail('Provisioning should reject a profile with different rate limits.');
        } catch (RouterProbeException $exception) {
            $this->assertStringContainsString('does not match', $exception->getMessage());
        }

        $this->assertSame(ProvisioningStatus::Failed, $voucher->fresh()->provisioning_status);
    }

    public function test_it_rejects_zero_byte_voucher_limits_before_router_provisioning(): void
    {
        $router = Router::factory()->create();
        $plan = Plan::factory()->create(['router_id' => $router->getKey()]);
        $voucher = Voucher::factory()->create([
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'hotspot_profile' => 'default',
            'provisioning_status' => ProvisioningStatus::Queued,
            'data_allowance_bytes' => 0,
        ]);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->never())->method('hotspotUserProfiles');
        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->never())->method('make');

        $service = new VoucherProvisioningService(
            $connections,
            $this->createMock(RouterApiRecorder::class),
            app(AuditLogger::class),
            $gateway,
        );

        try {
            $service->provision($voucher);
            $this->fail('Provisioning should reject a zero-byte finite allowance.');
        } catch (RouterProbeException $exception) {
            $this->assertStringContainsString('greater than zero', $exception->getMessage());
        }

        $this->assertSame(ProvisioningStatus::Failed, $voucher->fresh()->provisioning_status);
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
            'lifecycle_status' => VoucherLifecycleStatus::Expired->value,
            'provisioning_status' => ProvisioningStatus::Provisioned->value,
        ]);
    }

    public function test_it_marks_expired_vouchers_expired_when_the_router_is_unavailable(): void
    {
        $router = Router::factory()->create();
        $voucher = $this->createProvisionedVoucher($router, [
            'first_used_at' => now()->subHours(2),
            'expires_at' => now()->subMinute(),
        ]);
        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())
            ->method('make')
            ->willThrowException(RouterProbeException::configuration('Router unavailable'));
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('record')->willReturn(new AuditLog);

        $service = new VoucherValiditySyncService(
            $connections,
            $this->createMock(RouterApiRecorder::class),
            $audit,
        );

        $service->sync();

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->getKey(),
            'lifecycle_status' => VoucherLifecycleStatus::Expired->value,
            'provisioning_status' => ProvisioningStatus::Provisioned->value,
        ]);
    }

    public function test_it_marks_expired_vouchers_expired_even_when_not_provisioned(): void
    {
        $voucher = Voucher::factory()->create([
            'lifecycle_status' => VoucherLifecycleStatus::Active,
            'provisioning_status' => ProvisioningStatus::Failed,
            'expires_at' => now()->subMinute(),
        ]);
        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->never())->method('make');
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('record')->willReturn(new AuditLog);

        $service = new VoucherValiditySyncService(
            $connections,
            $this->createMock(RouterApiRecorder::class),
            $audit,
        );

        $service->sync();

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->getKey(),
            'lifecycle_status' => VoucherLifecycleStatus::Expired->value,
            'provisioning_status' => ProvisioningStatus::Failed->value,
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
            'time_allowance_seconds' => 1800,
            'upload_speed_kbps' => 512,
            'download_speed_kbps' => 2048,
        ]);
        RouterPlanProfile::query()->create([
            'router_id' => $router->getKey(),
            'plan_id' => $plan->getKey(),
            'hotspot_profile' => 'default',
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
            'hotspot_profile' => 'default',
            'time_allowance_seconds' => 1800,
            'upload_speed_kbps' => 512,
            'download_speed_kbps' => 2048,
            'validity_seconds' => 7200,
            'validity_starts' => 'first_use',
            'expiry_policy' => 'calendar',
        ]);

        $voucher = Voucher::query()->where('router_id', $router->getKey())->firstOrFail();
        $this->assertSame($voucher->username, $voucher->plainPassword());
    }

    public function test_it_refuses_to_provision_an_expired_voucher(): void
    {
        $router = Router::factory()->create();
        $voucher = Voucher::factory()->create([
            'router_id' => $router->getKey(),
            'provisioning_status' => ProvisioningStatus::Pending,
            'lifecycle_status' => VoucherLifecycleStatus::Active,
            'expires_at' => now()->subMinute(),
        ]);
        $gateway = $this->createMock(RouterGateway::class);
        $gateway->expects($this->never())->method('hotspotUserProfiles');
        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->never())->method('make');

        $service = new VoucherProvisioningService(
            $connections,
            $this->createMock(RouterApiRecorder::class),
            app(AuditLogger::class),
            $gateway,
        );

        try {
            $service->provision($voucher);
            $this->fail('Provisioning should reject an expired voucher.');
        } catch (RouterProbeException $exception) {
            $this->assertStringContainsString('no longer eligible', $exception->getMessage());
        }

        $this->assertSame(ProvisioningStatus::Failed, $voucher->fresh()->provisioning_status);
    }

    public function test_failed_revocation_marks_the_voucher_as_revocation_failed(): void
    {
        $router = Router::factory()->create();
        $voucher = Voucher::factory()->create([
            'router_id' => $router->getKey(),
            'provisioning_status' => ProvisioningStatus::Provisioned,
            'lifecycle_status' => VoucherLifecycleStatus::Active,
        ]);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('delete')
            ->willThrowException(new MikrotikException('Router unavailable'));
        $connections = $this->createMock(RouterConnectionFactory::class);
        $connections->expects($this->once())->method('make')->willReturn($client);

        $service = new VoucherProvisioningService(
            $connections,
            $this->createMock(RouterApiRecorder::class),
            app(AuditLogger::class),
            $this->createMock(RouterGateway::class),
        );

        try {
            $service->revoke($voucher);
            $this->fail('Revocation should fail when RouterOS rejects the request.');
        } catch (MikrotikException) {
            // Expected.
        }

        $voucher = $voucher->fresh();
        $this->assertSame(ProvisioningStatus::RevocationFailed, $voucher->provisioning_status);
        $this->assertStringContainsString('Router unavailable', (string) $voucher->provisioning_error);
    }

    public function test_revoke_job_failure_clears_a_stuck_disabling_status(): void
    {
        $voucher = Voucher::factory()->create([
            'provisioning_status' => ProvisioningStatus::Disabling,
        ]);

        (new RevokeVoucherJob($voucher->getKey()))->failed(new RuntimeException('Worker crashed.'));

        $voucher = $voucher->fresh();
        $this->assertSame(ProvisioningStatus::RevocationFailed, $voucher->provisioning_status);
        $this->assertSame('Worker crashed.', $voucher->provisioning_error);
    }

    public function test_bulk_printing_rejects_an_expired_voucher(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $voucher = Voucher::factory()->create([
            'provisioning_status' => ProvisioningStatus::Provisioned,
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($user);

        Livewire::test('pages::vouchers.index')
            ->set('selectedVouchers', [$voucher->getKey()])
            ->call('printSelected')
            ->assertHasErrors('selectedVouchers');
    }

    public function test_bulk_print_sheet_is_cleared_after_the_print_event(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $voucher = Voucher::factory()->create([
            'provisioning_status' => ProvisioningStatus::Provisioned,
        ]);

        $this->actingAs($user);

        Livewire::test('pages::vouchers.index')
            ->set('selectedVouchers', [$voucher->getKey()])
            ->call('printSelected')
            ->assertDispatched('print-vouchers')
            ->assertSet('printingVoucherIds', [$voucher->getKey()])
            ->call('clearPrintingVoucherIds')
            ->assertSet('printingVoucherIds', []);
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
