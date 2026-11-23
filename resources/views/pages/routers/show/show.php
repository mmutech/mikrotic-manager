<?php

use App\Contracts\RouterGateway;
use App\Enums\RouterJobStatus;
use App\Exceptions\RouterProbeException;
use App\Jobs\ConfigureRouterBridgeJob;
use App\Jobs\ConfigureRouterNetworkJob;
use App\Jobs\CreateRouterHotspotUserProfileJob;
use App\Jobs\TestRouterConnectionJob;
use App\Jobs\VerifyRouterNetworkJob;
use App\Models\Router;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Mikrotik\MikrotikRouterConfigurator;
use App\Services\RouterJobService;
use App\Services\RouterRegistryService;
use App\Support\CorrelationContext;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Router')] class extends Component
{
    public Router $router;

    public string $selectedUserId = '';

    public string $new_api_username = '';

    public string $new_api_password = '';

    public string $new_api_password_confirmation = '';

    public string $bridgeName = 'bridge-lan';

    /** @var array<int, array{name: string, running: bool, disabled: bool, bridge: ?string}> */
    public array $lanInterfaces = [];

    /** @var array<int, string> */
    public array $selectedLanInterfaces = [];

    public bool $lanInterfacesDiscovered = false;

    public bool $managementImpactConfirmed = false;

    public string $wanInterface = '';

    public string $networkBridgeName = '';

    public string $lanAddress = '';

    public string $poolStart = '';

    public string $poolEnd = '';

    public string $dnsServers = '';

    public string $managementCidrs = '';

    public bool $networkImpactConfirmed = false;

    public string $hotspotProfileName = '';

    public string $hotspotProfileUploadSpeedKbps = '';

    public string $hotspotProfileDownloadSpeedKbps = '';

    public string $hotspotProfileSharedUsers = '1';

    /** @var array<int, array{name: string, rate_limit: ?string, shared_users: string}> */
    public array $hotspotUserProfiles = [];

    public bool $hotspotUserProfilesDiscovered = false;

    /** @var array<int, array{ip: string, interface: ?string}> */
    public array $privateIpv4Addresses = [];

    public string $certificatePrivateIp = '';

    public bool $privateIpv4DiscoveryComplete = false;

    public function updatedBridgeName(): void
    {
        $this->managementImpactConfirmed = false;
        $this->networkImpactConfirmed = false;
    }

    public function updatedSelectedLanInterfaces(): void
    {
        $this->managementImpactConfirmed = false;
    }

    public function updated(string $property): void
    {
        if (in_array($property, [
            'wanInterface',
            'networkBridgeName',
            'lanAddress',
            'poolStart',
            'poolEnd',
            'dnsServers',
            'managementCidrs',
        ], true)) {
            $this->networkImpactConfirmed = false;
        }
    }

    public function mount(Router $router): void
    {
        $this->authorize('view', $router);

        $this->router = $router;
        $this->new_api_username = $router->api_username;
    }

    public function discoverPrivateIpv4Addresses(
        RouterGateway $gateway,
        CorrelationContext $correlation,
    ): void {
        $this->authorize('update', $this->router);

        try {
            $this->privateIpv4Addresses = $gateway->privateIpv4Addresses(
                $this->router,
                $correlation->id(),
            );
            $this->privateIpv4DiscoveryComplete = true;

            $matchingRouterHost = collect($this->privateIpv4Addresses)
                ->firstWhere('ip', $this->router->host);

            $this->certificatePrivateIp = $matchingRouterHost['ip']
                ?? (count($this->privateIpv4Addresses) === 1
                    ? $this->privateIpv4Addresses[0]['ip']
                    : '');

            $this->resetErrorBag('privateIpv4Addresses');
        } catch (RouterProbeException $exception) {
            $this->privateIpv4Addresses = [];
            $this->certificatePrivateIp = '';
            $this->privateIpv4DiscoveryComplete = false;
            $this->addError('privateIpv4Addresses', $exception->getMessage());
        }
    }

    public function test(RouterJobService $jobs, AuditLogger $audit): void
    {
        $this->authorize('test', $this->router);

        if ($jobs->pendingTestExists($this->router)) {
            session()->flash('error', 'A connection test is already queued or running for this router.');

            return;
        }

        $job = $jobs->create(
            router: $this->router,
            type: 'test_connection',
            actor: auth()->user(),
            idempotencyKey: 'router-test:'.$this->router->getKey(),
        );

        $audit->record(
            'router.test_queued',
            $this->router,
            metadata: ['job_uuid' => $job->uuid, 'source' => 'router_detail'],
            actor: auth()->user(),
        );

        TestRouterConnectionJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            actorId: auth()->id(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        session()->flash('status', 'A read-only connection test has been queued.');
    }

    public function discoverLanInterfaces(RouterGateway $gateway, CorrelationContext $correlation): void
    {
        $this->authorize('update', $this->router);

        try {
            $this->lanInterfaces = $gateway->ethernetInterfaces($this->router, $correlation->id());
            $this->lanInterfacesDiscovered = true;
            $this->selectedLanInterfaces = [];
            $this->wanInterface = '';
            $bridgeNames = collect($this->lanInterfaces)->pluck('bridge')->filter()->unique()->values();
            $this->networkBridgeName = $bridgeNames->count() === 1 ? $bridgeNames->first() : '';
            $this->managementImpactConfirmed = false;
            $this->networkImpactConfirmed = false;
            $this->resetErrorBag('lanInterfaces');
        } catch (RouterProbeException $exception) {
            $this->lanInterfaces = [];
            $this->lanInterfacesDiscovered = false;
            $this->addError('lanInterfaces', $exception->getMessage());
        }
    }

    public function applyBridgeConfiguration(
        RouterGateway $gateway,
        RouterJobService $jobs,
        AuditLogger $audit,
        CorrelationContext $correlation,
    ): void {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('bridgeConfiguration');
        $this->resetErrorBag('lanInterfaces');

        $this->validate([
            'bridgeName' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,31}\z/'],
            'managementImpactConfirmed' => ['accepted'],
        ], [
            'managementImpactConfirmed.accepted' => 'Confirm the management connectivity warning before applying changes.',
        ]);

        $pendingJobExists = $this->router->jobs()
            ->whereIn('status', [
                RouterJobStatus::Queued->value,
                RouterJobStatus::Running->value,
                RouterJobStatus::Retrying->value,
            ])
            ->exists();

        if ($pendingJobExists) {
            $this->addError('bridgeConfiguration', 'Wait for the current router operation to finish before configuring a bridge.');

            return;
        }

        try {
            $this->lanInterfaces = $gateway->ethernetInterfaces($this->router, $correlation->id());
            $this->lanInterfacesDiscovered = true;
            $this->resetErrorBag('lanInterfaces');
        } catch (RouterProbeException $exception) {
            $this->addError('lanInterfaces', $exception->getMessage());

            return;
        }

        $eligibleInterfaces = collect($this->lanInterfaces)
            ->filter(fn (array $interface): bool => $interface['bridge'] === null || $interface['bridge'] === $this->bridgeName)
            ->pluck('name')
            ->all();

        $validated = $this->validate([
            'selectedLanInterfaces' => ['required', 'array', 'min:1'],
            'selectedLanInterfaces.*' => ['required', 'string', 'distinct', Rule::in($eligibleInterfaces)],
        ], [
            'selectedLanInterfaces.required' => 'Select at least one Ethernet interface to add.',
            'selectedLanInterfaces.min' => 'Select at least one Ethernet interface to add.',
            'selectedLanInterfaces.*.in' => 'A selected interface is no longer available for this bridge.',
        ]);

        $interfaces = array_values($validated['selectedLanInterfaces']);
        $idempotencyHash = substr(hash('sha256', $this->bridgeName."\0".implode("\0", $interfaces)), 0, 32);
        $job = $jobs->create(
            router: $this->router,
            type: 'configure_bridge',
            actor: auth()->user(),
            payload: [
                'bridge_name' => $this->bridgeName,
                'interfaces' => $interfaces,
                'management_impact_confirmed' => $this->managementImpactConfirmed,
            ],
            idempotencyKey: 'router-bridge:'.$this->router->getKey().':'.$idempotencyHash,
        );

        $audit->record(
            'router.bridge_configuration_queued',
            $this->router,
            metadata: [
                'job_uuid' => $job->uuid,
                'bridge_name' => $this->bridgeName,
                'interfaces' => $interfaces,
            ],
            actor: auth()->user(),
        );

        ConfigureRouterBridgeJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        session()->flash(
            'status',
            'Bridge configuration has been queued. Check Router jobs for the result.',
        );
    }

    public function configureNetwork(
        RouterGateway $gateway,
        MikrotikRouterConfigurator $configurator,
        RouterJobService $jobs,
        AuditLogger $audit,
        CorrelationContext $correlation,
    ): void {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('networkConfiguration');
        $this->resetErrorBag('wanInterface');
        $this->resetErrorBag('networkBridgeName');

        $validated = $this->validate([
            'networkBridgeName' => ['required', 'string'],
            'wanInterface' => ['required', 'string'],
            'lanAddress' => ['required', 'string', 'max:18'],
            'poolStart' => ['required', 'string', 'max:15'],
            'poolEnd' => ['required', 'string', 'max:15'],
            'dnsServers' => ['required', 'string', 'max:500'],
            'managementCidrs' => ['required', 'string', 'max:2000'],
            'networkImpactConfirmed' => ['accepted'],
        ], [
            'networkImpactConfirmed.accepted' => 'Confirm the network-impact warning before applying changes.',
        ]);

        if ($this->router->jobs()->whereIn('status', [
            RouterJobStatus::Queued->value,
            RouterJobStatus::Running->value,
            RouterJobStatus::Retrying->value,
        ])->exists()) {
            $this->addError('networkConfiguration', 'Wait for the current router operation to finish before configuring the network.');

            return;
        }

        try {
            $this->lanInterfaces = $gateway->ethernetInterfaces($this->router, $correlation->id());
            $this->lanInterfacesDiscovered = true;
        } catch (RouterProbeException $exception) {
            $this->addError('networkConfiguration', $exception->getMessage());

            return;
        }

        $availableWanInterfaces = collect($this->lanInterfaces)
            ->filter(fn (array $interface): bool => $interface['bridge'] === null)
            ->pluck('name')
            ->all();
        $availableBridgeNames = collect($this->lanInterfaces)
            ->pluck('bridge')
            ->filter()
            ->unique()
            ->all();

        if (! in_array($validated['wanInterface'], $availableWanInterfaces, true)) {
            $this->addError('wanInterface', 'The selected WAN interface is no longer an available unbridged Ethernet port.');

            return;
        }

        if (! in_array($validated['networkBridgeName'], $availableBridgeNames, true)) {
            $this->addError('networkBridgeName', 'Select a discovered LAN bridge with at least one member interface.');

            return;
        }

        $configuration = [
            'bridge_name' => $validated['networkBridgeName'],
            'wan_interface' => $validated['wanInterface'],
            'lan_address' => trim($validated['lanAddress']),
            'pool_start' => trim($validated['poolStart']),
            'pool_end' => trim($validated['poolEnd']),
            'dns_servers' => $this->splitNetworkValues($validated['dnsServers']),
            'management_cidrs' => $this->splitNetworkValues($validated['managementCidrs']),
            'management_impact_confirmed' => $this->networkImpactConfirmed,
        ];

        try {
            $configuration = $configurator->normalize($configuration);
        } catch (RouterProbeException $exception) {
            $this->addError('networkConfiguration', $exception->getMessage());

            return;
        }

        $idempotencyHash = substr(hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)), 0, 32);
        $job = $jobs->create(
            router: $this->router,
            type: 'configure_network',
            actor: auth()->user(),
            payload: $configuration,
            idempotencyKey: 'router-network:'.$this->router->getKey().':'.$idempotencyHash,
        );

        $audit->record(
            'router.network_configuration_queued',
            $this->router,
            metadata: [
                'job_uuid' => $job->uuid,
                'bridge_name' => $configuration['bridge_name'],
                'wan_interface' => $configuration['wan_interface'],
                'network_cidr' => $configuration['network_cidr'],
            ],
            actor: auth()->user(),
        );

        ConfigureRouterNetworkJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        session()->flash('status', 'DHCP, NAT, and firewall configuration has been queued. Check Router jobs and the verification report.');
    }

    public function verifyNetwork(RouterJobService $jobs, AuditLogger $audit): void
    {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('networkVerification');

        if ($this->router->jobs()->whereIn('status', [
            RouterJobStatus::Queued->value,
            RouterJobStatus::Running->value,
            RouterJobStatus::Retrying->value,
        ])->exists()) {
            $this->addError('networkVerification', 'Wait for the current router operation to finish before verifying the network.');

            return;
        }

        $configurationJob = $this->router->jobs()
            ->where('type', 'configure_network')
            ->latest('created_at')
            ->first();
        $configuration = $configurationJob?->payload['configuration'] ?? null;

        if (! is_array($configuration)) {
            $this->addError('networkVerification', 'No saved network configuration is available to verify.');

            return;
        }

        $job = $jobs->create(
            router: $this->router,
            type: 'verify_network',
            actor: auth()->user(),
            payload: [
                'configuration' => $configuration,
                'source_job_uuid' => $configurationJob->uuid,
            ],
            idempotencyKey: 'router-network-verify:'.$this->router->getKey().':'.$configurationJob->uuid,
        );

        $audit->record(
            'router.network_verification_queued',
            $this->router,
            metadata: [
                'job_uuid' => $job->uuid,
                'source_job_uuid' => $configurationJob->uuid,
            ],
            actor: auth()->user(),
        );

        VerifyRouterNetworkJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        session()->flash('status', 'Read-only network verification has been queued.');
    }

    public function discoverRouterHotspotUserProfiles(
        RouterGateway $gateway,
        CorrelationContext $correlation,
    ): void {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('hotspotUserProfiles');

        try {
            $this->hotspotUserProfiles = $gateway->hotspotUserProfiles(
                $this->router,
                $correlation->id(),
            );
            $this->hotspotUserProfilesDiscovered = true;
        } catch (RouterProbeException $exception) {
            $this->hotspotUserProfiles = [];
            $this->hotspotUserProfilesDiscovered = false;
            $this->addError('hotspotUserProfiles', $exception->getMessage());
        }
    }

    public function createRouterHotspotUserProfile(
        RouterJobService $jobs,
        AuditLogger $audit,
    ): void {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('hotspotProfile');

        $validated = $this->validate([
            'hotspotProfileName' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/'],
            'hotspotProfileUploadSpeedKbps' => ['nullable', 'integer', 'min:1', 'required_with:hotspotProfileDownloadSpeedKbps'],
            'hotspotProfileDownloadSpeedKbps' => ['nullable', 'integer', 'min:1', 'required_with:hotspotProfileUploadSpeedKbps'],
            'hotspotProfileSharedUsers' => ['required', 'integer', 'min:1'],
        ], [
            'hotspotProfileName.regex' => 'Use 1 to 64 letters, numbers, dots, underscores, or hyphens; the name must start with a letter or number.',
            'hotspotProfileUploadSpeedKbps.required_with' => 'Enter both upload and download speeds, or leave both blank for no rate limit.',
            'hotspotProfileDownloadSpeedKbps.required_with' => 'Enter both upload and download speeds, or leave both blank for no rate limit.',
        ]);

        if ($this->router->jobs()->whereIn('status', [
            RouterJobStatus::Queued->value,
            RouterJobStatus::Running->value,
            RouterJobStatus::Retrying->value,
        ])->exists()) {
            $this->addError('hotspotProfile', 'Wait for the current router operation to finish before creating a Hotspot profile.');

            return;
        }

        $rateLimit = $validated['hotspotProfileUploadSpeedKbps'] === null
            ? null
            : (int) $validated['hotspotProfileUploadSpeedKbps'].'k/'.(int) $validated['hotspotProfileDownloadSpeedKbps'].'k';
        $configuration = [
            'profile_name' => $validated['hotspotProfileName'],
            'rate_limit' => $rateLimit,
            'shared_users' => (int) $validated['hotspotProfileSharedUsers'],
        ];
        $configurationHash = substr(hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)), 0, 32);

        $job = $jobs->create(
            router: $this->router,
            type: 'create_hotspot_user_profile',
            actor: auth()->user(),
            payload: $configuration,
            idempotencyKey: 'router-hotspot-user-profile:'.$this->router->getKey().':'.$configurationHash,
        );

        $audit->record(
            'router.hotspot_user_profile_creation_queued',
            $this->router,
            metadata: [
                'job_uuid' => $job->uuid,
                'profile_name' => $validated['hotspotProfileName'],
                'rate_limit' => $rateLimit,
                'shared_users' => $configuration['shared_users'],
            ],
            actor: auth()->user(),
        );

        CreateRouterHotspotUserProfileJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        session()->flash('status', 'Router-level Hotspot user profile creation has been queued.');
    }

    /**
     * @return array<int, string>
     */
    private function splitNetworkValues(string $values): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[,\r\n]+/', $values) ?: []),
            fn (string $value): bool => $value !== ''
        ));
    }

    public function toggleMonitoring(RouterRegistryService $registry): void
    {
        $this->authorize('manageMonitoring', $this->router);

        $enabled = ! $this->router->monitoring_enabled;

        $registry->setMonitoring($this->router, $enabled, auth()->user());
        $this->router->refresh();

        session()->flash('status', $enabled
            ? 'Health monitoring enabled. The next scheduled run will probe this router.'
            : 'Health monitoring disabled. Existing history has been retained.');
    }

    public function grantAccess(RouterRegistryService $registry): void
    {
        $this->authorize('grantAccess', $this->router);

        $this->validate([
            'selectedUserId' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('status', 'active'),
            ],
        ], [
            'selectedUserId.required' => 'Choose a staff member to grant access to.',
        ]);

        /** @var User $user */
        $user = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['super-admin', 'network-admin', 'support', 'finance']))
            ->findOrFail((int) $this->selectedUserId);

        if ($this->router->users()->whereKey($user->getKey())->exists()) {
            session()->flash('error', $user->name.' already has access to this router.');

            return;
        }

        $registry->grantAccess($this->router, $user, auth()->user());
        $this->router->load('users');
        $this->reset('selectedUserId');

        session()->flash('status', 'Access granted to '.$user->name.'.');
    }

    public function revokeAccess(int $userId, RouterRegistryService $registry): void
    {
        $this->authorize('revokeAccess', $this->router);

        /** @var User $user */
        $user = $this->router->users()->whereKey($userId)->firstOrFail();

        $registry->revokeAccess($this->router, $user, auth()->user());
        $this->router->load('users');

        session()->flash('status', 'Access revoked for '.$user->name.'.');
    }

    public function rotateCredentials(
        RouterRegistryService $registry,
        RouterJobService $jobs,
        AuditLogger $audit,
    ): void {
        $this->authorize('update', $this->router);

        $validated = $this->validate([
            'new_api_username' => ['required', 'string', 'max:120'],
            'new_api_password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $registry->update($this->router, $validated, auth()->user());
        $this->router->refresh();

        $job = $jobs->create(
            router: $this->router,
            type: 'test_connection',
            actor: auth()->user(),
            idempotencyKey: 'router-test:'.$this->router->getKey(),
        );

        $audit->record(
            'router.test_queued',
            $this->router,
            metadata: ['job_uuid' => $job->uuid, 'source' => 'credential_rotation'],
            actor: auth()->user(),
        );

        TestRouterConnectionJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            actorId: auth()->id(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        $this->reset('new_api_password', 'new_api_password_confirmation');

        session()->flash('status', 'Router credentials rotated. A read-only test has been queued.');
    }

    public function archive(RouterRegistryService $registry): void
    {
        $this->authorize('delete', $this->router);

        $name = $this->router->name;
        $registry->archive($this->router, auth()->user());

        session()->flash('status', $name.' has been archived and removed from active monitoring.');

        $this->redirectRoute('routers.index', navigate: true);
    }

    public function render()
    {
        $user = auth()->user();
        $router = $this->router;
        $router->load('users');

        $pendingJob = $router->jobs()
            ->whereIn('status', [
                RouterJobStatus::Queued->value,
                RouterJobStatus::Running->value,
                RouterJobStatus::Retrying->value,
            ])
            ->latest('created_at')
            ->first();
        $networkJobs = $router->jobs()
            ->whereIn('type', ['configure_network', 'verify_network'])
            ->latest('created_at')
            ->limit(20)
            ->get();
        $latestNetworkReportJob = $networkJobs->first(
            fn ($job): bool => is_array($job->payload['verification'] ?? null)
        );
        $latestNetworkJob = $networkJobs->first();
        $latestNetworkConfigurationJob = $router->jobs()
            ->where('type', 'configure_network')
            ->whereNotNull('payload->configuration')
            ->latest('created_at')
            ->first();
        $staff = $user->can('grantAccess', $router)
            ? User::query()
                ->where('status', 'active')
                ->whereHas('roles', fn ($query) => $query->whereIn('name', ['super-admin', 'network-admin', 'support', 'finance']))
                ->whereKeyNot($router->users->pluck('id'))
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
            : collect();

        return $this->view([
            'canTest' => $user->can('test', $router),
            'canEdit' => $user->can('update', $router),
            'canMonitor' => $user->can('manageMonitoring', $router),
            'canGrant' => $user->can('grantAccess', $router),
            'canRevoke' => $user->can('revokeAccess', $router),
            'canArchive' => $user->can('delete', $router),
            'pendingJob' => $pendingJob,
            'latestNetworkJob' => $latestNetworkJob,
            'latestNetworkReportJob' => $latestNetworkReportJob,
            'canVerifyNetwork' => is_array($latestNetworkConfigurationJob?->payload['configuration'] ?? null),
            'availableBridgeNames' => collect($this->lanInterfaces)->pluck('bridge')->filter()->unique()->values(),
            'healthChecks' => $router->healthChecks()->latest('checked_at')->limit(8)->get(),
            'jobs' => $router->jobs()->latest('created_at')->limit(8)->get(),
            'apiLogs' => $router->apiLogs()->latest('created_at')->limit(8)->get(),
            'staff' => $staff,
            'pendingJobCount' => $router->pendingJobCount(),
            'usableVoucherCount' => $router->usableVoucherCount(),
            'provisioningSummary' => $router->provisioningSummary(),
        ])->title($router->name);
    }
};
