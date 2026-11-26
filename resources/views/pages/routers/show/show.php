<?php

use App\Contracts\RouterGateway;
use App\Enums\RouterJobStatus;
use App\Exceptions\RouterProbeException;
use App\Jobs\ConfigureRouterBridgeJob;
use App\Jobs\ConfigureRouterHotspotJob;
use App\Jobs\ConfigureRouterNetworkJob;
use App\Jobs\CreateRouterHotspotUserProfileJob;
use App\Jobs\EnableRouterHotspotCookieLoginJob;
use App\Jobs\ResetRouterManagedConfigurationJob;
use App\Jobs\RestoreRouterConfigurationJob;
use App\Jobs\SyncRouterIdentityJob;
use App\Jobs\TestRouterConnectionJob;
use App\Jobs\UpdateRouterHotspotUserProfileJob;
use App\Jobs\VerifyRouterNetworkJob;
use App\Models\Router;
use App\Models\RouterJob;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Mikrotik\MikrotikRouterConfigurator;
use App\Services\RouterJobService;
use App\Services\RouterRegistryService;
use App\Support\CorrelationContext;
use App\Support\HotspotRateLimit;
use App\Support\HotspotServerConfiguration;
use App\Support\RouterIdentity;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Layout('layouts::app')] #[Title('Router')] class extends Component
{
    use WithFileUploads;

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

    /** @var array<int, string> */
    public array $selectedLanInterfacesToRemove = [];

    public bool $lanInterfacesDiscovered = false;

    public bool $managementImpactConfirmed = false;

    public string $wanInterface = '';

    public string $networkBridgeName = '';

    public string $lanAddress = '192.168.88.1/23';

    public string $poolStart = '192.168.88.2';

    public string $poolEnd = '192.168.89.254';

    public string $dnsServers = '1.1.1.1,8.8.8.8';

    public string $managementCidrs = '192.168.2.70';

    public string $managementCidrDetectionNote = '';

    public bool $networkFormVisible = false;

    public bool $networkImpactConfirmed = false;

    #[Locked]
    public array $networkCommands = [];

    /** @var array<string, array<int, array{id: ?string, title: string, detail: string, comment: ?string, disabled: bool, managed: bool}>> */
    public array $networkDiscovery = [
        'addresses' => [],
        'pools' => [],
        'dhcp_networks' => [],
        'dhcp_servers' => [],
        'nat_rules' => [],
        'firewall_rules' => [],
    ];

    public bool $networkConfigurationDiscovered = false;

    #[Locked]
    public string $hotspotServerName = '';

    public string $hotspotServerInterface = '';

    #[Locked]
    public string $hotspotServerPool = '';

    public string $hotspotServerProfile = 'default';

    /** @var array<int, array{name: string, type: string, running: bool, disabled: bool}> */
    public array $hotspotServerInterfaces = [];

    /** @var array<int, array{name: string, ranges: ?string}> */
    public array $hotspotServerPools = [];

    /** @var array<int, string> */
    public array $hotspotServerProfiles = [];

    /** @var array<int, array{id: ?string, name: string, interface: ?string, address_pool: ?string, profile: ?string, disabled: bool, managed: bool}> */
    public array $hotspotServers = [];

    public bool $hotspotServerDiscovered = false;

    /** @var array<int, string> */
    #[Locked]
    public array $hotspotServerCommands = [];

    #[Locked]
    public string $confirmationDialog = '';

    #[Locked]
    public int $confirmationMemberId = 0;

    public string $resetConfirmation = '';

    public string $restoreConfirmation = '';

    /** @var TemporaryUploadedFile|null */
    public $restoreFile = null;

    public string $hotspotProfileName = '';

    public string $hotspotProfileUploadSpeedKbps = '';

    public string $hotspotProfileDownloadSpeedKbps = '';

    public string $hotspotProfileSharedUsers = '1';

    public bool $hotspotProfileFormVisible = false;

    public ?string $editingHotspotProfileId = null;

    public ?string $editingHotspotProfileName = null;

    public ?string $hotspotProfileExpectedRateLimit = null;

    public string $hotspotProfileExpectedSharedUsers = '1';

    /** @var array<int, array{id: ?string, name: string, rate_limit: ?string, shared_users: string}> */
    public array $hotspotUserProfiles = [];

    public bool $hotspotUserProfilesDiscovered = false;

    /** @var array<int, array{ip: string, interface: ?string}> */
    public array $privateIpv4Addresses = [];

    public string $certificatePrivateIp = '';

    public bool $privateIpv4DiscoveryComplete = false;

    public function updatedBridgeName(): void
    {
        $this->selectedLanInterfaces = [];
        $this->selectedLanInterfacesToRemove = [];
        $this->managementImpactConfirmed = false;
        $this->networkImpactConfirmed = false;
    }

    public function updatedSelectedLanInterfaces(): void
    {
        $this->managementImpactConfirmed = false;
    }

    public function updatedSelectedLanInterfacesToRemove(): void
    {
        $this->managementImpactConfirmed = false;
    }

    public function updatedRestoreFile(): void
    {
        $this->resetErrorBag('restoreFile');
        $this->restoreConfirmation = '';
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
            'networkImpactConfirmed',
        ], true)) {
            $this->networkCommands = [];
        }

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

        if (in_array($property, [
            'hotspotServerInterface',
            'hotspotServerPool',
            'hotspotServerProfile',
        ], true)) {
            $this->hotspotServerCommands = [];
        }
    }

    public function mount(Router $router): void
    {
        $this->authorize('view', $router);

        $this->router = $router;
        $this->new_api_username = $router->api_username;

        $this->syncIdentityDerivedHotspotSettings();

        $networkJob = $router->jobs()
            ->where('type', 'configure_network')
            ->whereNotNull('payload->configuration')
            ->latest('created_at')
            ->first();
        $networkConfiguration = $networkJob?->payload['configuration'] ?? [];
        $this->hotspotServerInterface = is_string($networkConfiguration['bridge_name'] ?? null)
            ? $networkConfiguration['bridge_name']
            : '';

        $this->markCurrentJobFailureAlertsShown();
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
            $this->dispatchAlert(
                'Connection test already queued',
                'A connection test is already queued or running for this router.',
                'error',
            );

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

        $this->dispatchAlert('Connection test queued', 'A read-only connection test has been queued.');
    }

    public function provisionIdentity(RouterJobService $jobs, AuditLogger $audit): void
    {
        $this->authorize('update', $this->router);

        try {
            $identity = RouterIdentity::normalize($this->router->identity, $this->router->name);
        } catch (RouterProbeException $exception) {
            $this->dispatchAlert('Identity provisioning not queued', $exception->getMessage(), 'error');

            return;
        }

        $job = $jobs->create(
            router: $this->router,
            type: 'sync_identity',
            actor: auth()->user(),
            payload: ['identity' => $identity],
            idempotencyKey: 'router-identity:'.$this->router->getKey().':'.hash('sha256', $identity),
        );

        $audit->record(
            'router.identity_sync_queued',
            $this->router,
            metadata: ['job_uuid' => $job->uuid, 'source' => 'router_detail', 'identity' => $identity],
            actor: auth()->user(),
        );

        SyncRouterIdentityJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        $this->dispatchAlert(
            'Identity provisioning queued',
            'A tracked RouterOS identity job will apply '.$identity.' and verify it by read-back.',
        );
    }

    public function discoverLanInterfaces(RouterGateway $gateway, CorrelationContext $correlation): void
    {
        $this->authorize('update', $this->router);
        $this->networkCommands = [];

        try {
            $this->lanInterfaces = $gateway->ethernetInterfaces($this->router, $correlation->id());
            $this->lanInterfacesDiscovered = true;
            $this->selectedLanInterfaces = [];
            $this->selectedLanInterfacesToRemove = [];
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

    public function discoverNetworkConfiguration(RouterGateway $gateway, CorrelationContext $correlation): void
    {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('networkDiscovery');

        try {
            $this->networkDiscovery = $gateway->networkConfiguration($this->router, $correlation->id());
            $this->networkConfigurationDiscovered = true;
        } catch (RouterProbeException $exception) {
            $this->networkDiscovery = [
                'addresses' => [],
                'pools' => [],
                'dhcp_networks' => [],
                'dhcp_servers' => [],
                'nat_rules' => [],
                'firewall_rules' => [],
            ];
            $this->networkConfigurationDiscovered = false;
            $this->addError('networkDiscovery', $exception->getMessage());
        }
    }

    public function discoverManagementSourceCidrs(
        RouterGateway $gateway,
        CorrelationContext $correlation,
    ): void {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('managementCidrs');
        $this->managementCidrDetectionNote = '';
        $this->networkCommands = [];
        $this->networkImpactConfirmed = false;

        try {
            $cidrs = $gateway->managementSourceCidrs($this->router, $correlation->id());
        } catch (RouterProbeException $exception) {
            $this->addError('managementCidrs', $exception->getMessage());

            return;
        }

        if ($cidrs === []) {
            $this->addError('managementCidrs', 'No management source CIDRs were detected. Enter them manually.');

            return;
        }

        $this->managementCidrs = implode("\n", $cidrs);
        $this->managementCidrDetectionNote = "Last auto-detect resolved this server's source network to ".count($cidrs).' CIDR(s). Review before applying.';
    }

    public function toggleNetworkForm(): void
    {
        $this->authorize('update', $this->router);

        $this->networkFormVisible = ! $this->networkFormVisible;
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
        $this->resetErrorBag('selectedLanInterfaces');
        $this->resetErrorBag('selectedLanInterfacesToRemove');

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

        $addEligibleInterfaces = collect($this->lanInterfaces)
            ->filter(fn (array $interface): bool => $interface['bridge'] === null)
            ->pluck('name')
            ->all();
        $removeEligibleInterfaces = collect($this->lanInterfaces)
            ->filter(fn (array $interface): bool => $interface['bridge'] === $this->bridgeName)
            ->pluck('name')
            ->all();

        $validated = $this->validate([
            'selectedLanInterfaces' => ['array'],
            'selectedLanInterfaces.*' => ['required', 'string', 'distinct', Rule::in($addEligibleInterfaces)],
            'selectedLanInterfacesToRemove' => ['array'],
            'selectedLanInterfacesToRemove.*' => ['required', 'string', 'distinct', Rule::in($removeEligibleInterfaces)],
        ], [
            'selectedLanInterfaces.*.in' => 'A selected interface is no longer available to add to this bridge.',
            'selectedLanInterfacesToRemove.*.in' => 'A selected interface is no longer a member of this bridge.',
        ]);

        $interfaces = array_values($validated['selectedLanInterfaces']);
        $interfacesToRemove = array_values($validated['selectedLanInterfacesToRemove']);

        if ($interfaces === [] && $interfacesToRemove === []) {
            $this->addError('bridgeConfiguration', 'Select at least one interface to add or remove.');

            return;
        }

        if (array_intersect($interfaces, $interfacesToRemove) !== []) {
            $this->addError('bridgeConfiguration', 'An interface cannot be added and removed in the same operation.');

            return;
        }

        $idempotencyHash = substr(
            hash('sha256', json_encode([
                $this->bridgeName,
                $interfaces,
                $interfacesToRemove,
            ], JSON_THROW_ON_ERROR)),
            0,
            32,
        );
        $job = $jobs->create(
            router: $this->router,
            type: 'configure_bridge',
            actor: auth()->user(),
            payload: [
                'bridge_name' => $this->bridgeName,
                'interfaces' => $interfaces,
                'remove_interfaces' => $interfacesToRemove,
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
                'remove_interfaces' => $interfacesToRemove,
            ],
            actor: auth()->user(),
        );

        ConfigureRouterBridgeJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        $this->dispatchAlert(
            'Bridge changes queued',
            'The selected bridge port additions and removals have been queued. Check Router jobs for the result.',
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

        $validated = $this->validate(
            $this->networkConfigurationRules(),
            $this->networkConfigurationMessages(),
        );

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

        $configuration = $this->networkConfigurationFromValidated($validated);

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

        $this->networkCommands = [];
        $this->dispatchAlert(
            'Network configuration queued',
            'DHCP, NAT, and firewall configuration has been queued. Check Router jobs and the verification report.',
        );
    }

    public function generateNetworkCommands(MikrotikRouterConfigurator $configurator): void
    {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('networkCommands');
        $this->networkCommands = [];

        $validated = $this->validate(
            $this->networkConfigurationRules(),
            $this->networkConfigurationMessages(),
        );

        if (! $this->lanInterfacesDiscovered) {
            $this->addError('networkCommands', 'Discover the router interfaces before generating terminal commands.');

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
            $this->addError('networkCommands', 'Rediscover interfaces and select an unbridged Ethernet port for WAN.');

            return;
        }

        if (! in_array($validated['networkBridgeName'], $availableBridgeNames, true)) {
            $this->addError('networkCommands', 'Rediscover interfaces and select a LAN bridge with member ports.');

            return;
        }

        if ($this->router->jobs()->whereIn('status', [
            RouterJobStatus::Queued->value,
            RouterJobStatus::Running->value,
            RouterJobStatus::Retrying->value,
        ])->exists()) {
            $this->addError('networkCommands', 'Wait for the current router operation to finish before generating commands.');

            return;
        }

        try {
            $this->networkCommands = $configurator->terminalCommands(
                $this->router,
                $this->networkConfigurationFromValidated($validated),
            );
        } catch (RouterProbeException $exception) {
            $this->addError('networkCommands', $exception->getMessage());
        }
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

        $this->dispatchAlert('Network verification queued', 'Read-only network verification has been queued.');
    }

    public function discoverHotspotServerSettings(
        RouterGateway $gateway,
        CorrelationContext $correlation,
    ): void {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('hotspotServerDiscovery');
        $this->syncIdentityDerivedHotspotSettings();

        try {
            $this->hotspotServerInterfaces = $gateway->routerInterfaces($this->router, $correlation->id());
            $this->hotspotServerPools = $gateway->ipPools($this->router, $correlation->id());
            $this->hotspotServerProfiles = array_values(array_filter(array_map(
                fn (array $profile): string => is_string($profile['name'] ?? null) ? $profile['name'] : '',
                $gateway->hotspotProfiles($this->router, $correlation->id()),
            )));
            $this->hotspotServers = $gateway->hotspotServers($this->router, $correlation->id());
            $this->hotspotServerDiscovered = true;
        } catch (RouterProbeException $exception) {
            $this->hotspotServerInterfaces = [];
            $this->hotspotServerPools = [];
            $this->hotspotServerProfiles = [];
            $this->hotspotServers = [];
            $this->hotspotServerDiscovered = false;
            $this->addError('hotspotServerDiscovery', $exception->getMessage());

            return;
        }

        if ($this->hotspotServerInterface === '') {
            $bridge = collect($this->hotspotServerInterfaces)->first(
                fn (array $interface): bool => $interface['type'] === 'bridge' && ! $interface['disabled'],
            );
            $this->hotspotServerInterface = $bridge['name'] ?? $this->hotspotServerInterfaces[0]['name'] ?? '';
        }

        if ($this->hotspotServerProfile === '' || ! in_array($this->hotspotServerProfile, $this->hotspotServerProfiles, true)) {
            $this->hotspotServerProfile = $this->hotspotServerProfiles[0] ?? '';
        }
    }

    public function openHotspotServerConfirmation(): void
    {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('hotspotServerConfiguration');

        if (! $this->syncIdentityDerivedHotspotSettings()) {
            return;
        }

        $this->confirmationDialog = 'hotspot_server';
    }

    public function configureHotspotServer(
        RouterJobService $jobs,
        AuditLogger $audit,
    ): void {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('hotspotServerConfiguration');

        if (! $this->syncIdentityDerivedHotspotSettings()) {
            return;
        }

        if (! $this->hotspotServerDiscovered) {
            $this->addError('hotspotServerConfiguration', 'Discover the router HotSpot settings before configuring the server.');

            return;
        }

        $validated = $this->validate(
            $this->hotspotServerConfigurationRules(),
            $this->hotspotServerConfigurationMessages(),
        );

        if ($this->router->jobs()->whereIn('status', [
            RouterJobStatus::Queued->value,
            RouterJobStatus::Running->value,
            RouterJobStatus::Retrying->value,
        ])->exists()) {
            $this->addError('hotspotServerConfiguration', 'Wait for the current router operation to finish before configuring the HotSpot server.');

            return;
        }

        $configuration = $this->hotspotServerConfigurationFromValidated($validated);
        $configurationHash = substr(hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)), 0, 32);
        $job = $jobs->create(
            router: $this->router,
            type: 'configure_hotspot',
            actor: auth()->user(),
            payload: $configuration,
            idempotencyKey: 'router-hotspot-server:'.$this->router->getKey().':'.$configurationHash,
        );

        $audit->record(
            'router.hotspot_server_configuration_queued',
            $this->router,
            metadata: [
                'job_uuid' => $job->uuid,
                'server_name' => $configuration['server_name'],
                'interface' => $configuration['interface'],
                'address_pool' => $configuration['address_pool'],
                'profile' => $configuration['profile'],
            ],
            actor: auth()->user(),
        );

        ConfigureRouterHotspotJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        $this->hotspotServerCommands = [];
        $this->closeConfirmationDialog();
        $this->dispatchAlert(
            'HotSpot configuration queued',
            'RouterOS HotSpot server configuration has been queued. Check Router jobs and the verification report.',
        );
    }

    public function generateHotspotServerCommands(): void
    {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('hotspotServerCommands');
        $this->hotspotServerCommands = [];

        if (! $this->syncIdentityDerivedHotspotSettings()) {
            return;
        }

        if (! $this->hotspotServerDiscovered) {
            $this->addError('hotspotServerCommands', 'Discover the router HotSpot settings before generating terminal commands.');

            return;
        }

        $validated = $this->validate(
            $this->hotspotServerConfigurationRules(),
            $this->hotspotServerConfigurationMessages(),
        );

        $this->hotspotServerCommands = HotspotServerConfiguration::terminalCommands(
            $this->hotspotServerConfigurationFromValidated($validated),
        );
    }

    public function discoverRouterHotspotUserProfiles(
        RouterGateway $gateway,
        CorrelationContext $correlation,
    ): void {
        $this->authorize('update', $this->router);

        try {
            $this->hotspotUserProfiles = $gateway->hotspotUserProfiles(
                $this->router,
                $correlation->id(),
            );
            $this->hotspotUserProfilesDiscovered = true;
        } catch (RouterProbeException $exception) {
            $this->hotspotUserProfiles = [];
            $this->hotspotUserProfilesDiscovered = false;
            $this->dispatchAlert('HotSpot profiles unavailable', $exception->getMessage(), 'error');
        }
    }

    public function toggleHotspotProfileForm(): void
    {
        $this->authorize('update', $this->router);

        $this->hotspotProfileFormVisible = ! $this->hotspotProfileFormVisible;
    }

    public function editRouterHotspotUserProfile(string $profileId): void
    {
        $this->authorize('update', $this->router);

        $profile = collect($this->hotspotUserProfiles)
            ->firstWhere('id', $profileId);

        if (! is_array($profile)) {
            $this->dispatchAlert(
                'Profile unavailable',
                'Refresh the router profile list before editing this profile.',
                'error',
            );

            return;
        }

        try {
            HotspotRateLimit::canonical($profile['rate_limit'] ?? null);
            $speeds = HotspotRateLimit::speedsKbps($profile['rate_limit'] ?? null);
        } catch (InvalidArgumentException $exception) {
            $this->dispatchAlert('Unsupported rate limit', $exception->getMessage(), 'error');

            return;
        }

        $this->editingHotspotProfileId = $profileId;
        $this->editingHotspotProfileName = (string) $profile['name'];
        $this->hotspotProfileName = (string) $profile['name'];
        $this->hotspotProfileUploadSpeedKbps = $speeds === null ? '' : (string) $speeds['upload'];
        $this->hotspotProfileDownloadSpeedKbps = $speeds === null ? '' : (string) $speeds['download'];
        $this->hotspotProfileSharedUsers = (string) ($profile['shared_users'] ?? '1');
        $this->hotspotProfileExpectedRateLimit = $profile['rate_limit'] ?? null;
        $this->hotspotProfileExpectedSharedUsers = (string) ($profile['shared_users'] ?? '1');
        $this->hotspotProfileFormVisible = true;

        foreach ([
            'hotspotProfileName',
            'hotspotProfileUploadSpeedKbps',
            'hotspotProfileDownloadSpeedKbps',
            'hotspotProfileSharedUsers',
        ] as $field) {
            $this->resetErrorBag($field);
        }
    }

    public function resetHotspotProfileForm(): void
    {
        $this->authorize('update', $this->router);

        $this->editingHotspotProfileId = null;
        $this->editingHotspotProfileName = null;
        $this->hotspotProfileExpectedRateLimit = null;
        $this->hotspotProfileExpectedSharedUsers = '1';
        $this->hotspotProfileName = '';
        $this->hotspotProfileUploadSpeedKbps = '';
        $this->hotspotProfileDownloadSpeedKbps = '';
        $this->hotspotProfileSharedUsers = '1';

        foreach ([
            'hotspotProfileName',
            'hotspotProfileUploadSpeedKbps',
            'hotspotProfileDownloadSpeedKbps',
            'hotspotProfileSharedUsers',
        ] as $field) {
            $this->resetErrorBag($field);
        }
    }

    public function saveRouterHotspotUserProfile(
        RouterJobService $jobs,
        AuditLogger $audit,
    ): void {
        $this->authorize('update', $this->router);

        if ($this->editingHotspotProfileId !== null) {
            $this->updateRouterHotspotUserProfile($jobs, $audit);

            return;
        }

        $this->createRouterHotspotUserProfile($jobs, $audit);
    }

    public function openArchiveConfirmation(): void
    {
        $this->authorize('delete', $this->router);
        $this->confirmationDialog = 'archive';
    }

    public function openCookieLoginConfirmation(): void
    {
        $this->authorize('update', $this->router);
        $this->confirmationDialog = 'cookie_login';
    }

    public function openRevokeAccessConfirmation(int $userId): void
    {
        $this->authorize('revokeAccess', $this->router);
        $this->router->users()->whereKey($userId)->firstOrFail();
        $this->confirmationMemberId = $userId;
        $this->confirmationDialog = 'revoke_access';
    }

    public function closeConfirmationDialog(): void
    {
        $this->confirmationDialog = '';
        $this->confirmationMemberId = 0;
        $this->resetConfirmation = '';
        $this->restoreConfirmation = '';
        $this->resetErrorBag(['resetConfirmation', 'restoreConfirmation']);
    }

    public function openResetConfirmation(): void
    {
        $this->authorize('update', $this->router);

        if ($this->routerHasPendingJob()) {
            $this->dispatchAlert(
                'Operation already queued',
                'Wait for the current router job to finish before resetting managed configuration.',
                'error',
            );

            return;
        }

        $this->resetConfirmation = '';
        $this->resetErrorBag('resetConfirmation');
        $this->confirmationDialog = 'reset';
    }

    public function openRestoreConfirmation(): void
    {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('restoreFile');

        if (! is_object($this->restoreFile) || ! method_exists($this->restoreFile, 'getPathname')) {
            $this->addError('restoreFile', 'Choose a RouterOS .rsc export to restore.');

            return;
        }

        if (strtolower((string) $this->restoreFile->getClientOriginalExtension()) !== 'rsc') {
            $this->addError('restoreFile', 'Only RouterOS .rsc export files can be restored.');

            return;
        }

        $size = (int) $this->restoreFile->getSize();

        if ($size < 1 || $size > 61439) {
            $this->addError('restoreFile', 'The .rsc file must be between 1 and 61439 bytes.');

            return;
        }

        if ($this->routerHasPendingJob()) {
            $this->dispatchAlert(
                'Operation already queued',
                'Wait for the current router job to finish before restoring a configuration.',
                'error',
            );

            return;
        }

        $this->restoreConfirmation = '';
        $this->resetErrorBag('restoreConfirmation');
        $this->confirmationDialog = 'restore';
    }

    public function downloadBackup(
        RouterGateway $gateway,
        RouterJobService $jobs,
        AuditLogger $audit,
        CorrelationContext $correlation,
    ) {
        $this->authorize('update', $this->router);

        if ($this->routerHasPendingJob()) {
            $this->dispatchAlert(
                'Operation already queued',
                'Wait for the current router job to finish before exporting a backup.',
                'error',
            );

            return null;
        }

        $fileName = 'mmutech-backup-'.$this->router->getKey().'-'.now()->format('YmdHis')
            .'-'.Str::lower(Str::random(6)).'.rsc';
        $correlationId = $correlation->id();
        $job = $jobs->create(
            router: $this->router,
            type: 'create_backup',
            actor: auth()->user(),
            payload: ['file_name' => $fileName],
        );
        $jobs->markRunning($job);

        try {
            $result = $gateway->exportConfiguration($this->router, $fileName, $correlationId);
            $payload = $job->payload ?? [];
            $payload['verification'] = $result['checks'];
            $payload['verification_at'] = now()->toIso8601String();
            $payload['size'] = strlen($result['contents']);
            $payload['sha256'] = hash('sha256', $result['contents']);
            $job->forceFill(['payload' => $payload])->save();
            $jobs->markSucceeded($job);

            $audit->record(
                'router.backup_downloaded',
                $this->router,
                metadata: [
                    'job_uuid' => $job->uuid,
                    'file_name' => $fileName,
                    'size' => strlen($result['contents']),
                ],
                actor: auth()->user(),
                correlationId: $correlationId,
            );
        } catch (Throwable $exception) {
            $category = $exception instanceof RouterProbeException
                ? $exception->category->value
                : null;
            $jobs->markFailed($job, $exception, $category);
            $audit->record(
                'router.backup_failed',
                $this->router,
                metadata: ['job_uuid' => $job->uuid, 'file_name' => $fileName],
                actor: auth()->user(),
                correlationId: $correlationId,
            );
            $this->dispatchAlert('Backup failed', $exception->getMessage(), 'error');

            return null;
        }

        return response()->streamDownload(
            function () use ($result): void {
                echo $result['contents'];
            },
            $fileName,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    public function resetManagedConfiguration(
        RouterGateway $gateway,
        RouterJobService $jobs,
        AuditLogger $audit,
    ): void {
        $this->authorize('update', $this->router);
        $this->resetErrorBag('resetConfirmation');

        try {
            $identity = RouterIdentity::fromRouter($this->router);
        } catch (RouterProbeException $exception) {
            $this->addError('resetConfirmation', $exception->getMessage());

            return;
        }

        if ($this->resetConfirmation !== $identity) {
            $this->addError('resetConfirmation', 'Type '.$identity.' exactly to confirm the reset.');

            return;
        }

        if ($this->routerHasPendingJob()) {
            $this->dispatchAlert(
                'Operation already queued',
                'Wait for the current router job to finish before resetting managed configuration.',
                'error',
            );

            return;
        }

        $scope = $this->managedResetScope();
        $job = $jobs->create(
            router: $this->router,
            type: 'reset_managed_configuration',
            actor: auth()->user(),
            payload: [
                'identity' => $identity,
                'bridge_name' => $scope['bridge_name'],
                'interfaces' => $scope['interfaces'],
                'profile_names' => $scope['profile_names'],
                'scope' => 'mmutech-managed-only',
            ],
        );

        $audit->record(
            'router.managed_reset_queued',
            $this->router,
            metadata: [
                'job_uuid' => $job->uuid,
                'identity' => $identity,
                'bridge_name' => $scope['bridge_name'],
                'interfaces' => $scope['interfaces'],
            ],
            actor: auth()->user(),
        );

        ResetRouterManagedConfigurationJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            identity: $identity,
            bridgeName: $scope['bridge_name'],
            managedInterfaces: $scope['interfaces'],
            managedProfileNames: $scope['profile_names'],
            correlationId: $job->correlation_id,
        )->afterCommit();

        $this->closeConfirmationDialog();
        $this->dispatchAlert(
            'Managed reset queued',
            'MMUTech-managed router configuration will be removed and read back for verification.',
        );
    }

    public function restoreConfiguration(
        RouterJobService $jobs,
        AuditLogger $audit,
    ): void {
        $this->authorize('update', $this->router);
        $this->resetErrorBag(['restoreFile', 'restoreConfirmation']);

        if (! is_object($this->restoreFile) || ! method_exists($this->restoreFile, 'getPathname')) {
            $this->addError('restoreFile', 'Choose a RouterOS .rsc export to restore.');

            return;
        }

        if (strtolower((string) $this->restoreFile->getClientOriginalExtension()) !== 'rsc') {
            $this->addError('restoreFile', 'Only RouterOS .rsc export files can be restored.');

            return;
        }

        try {
            $identity = RouterIdentity::fromRouter($this->router);
        } catch (RouterProbeException $exception) {
            $this->addError('restoreConfirmation', $exception->getMessage());

            return;
        }

        if ($this->restoreConfirmation !== $identity) {
            $this->addError('restoreConfirmation', 'Type '.$identity.' exactly to confirm the restore.');

            return;
        }

        if ($this->routerHasPendingJob()) {
            $this->dispatchAlert(
                'Operation already queued',
                'Wait for the current router job to finish before restoring a configuration.',
                'error',
            );

            return;
        }

        $contents = file_get_contents($this->restoreFile->getRealPath());

        if (! is_string($contents) || $contents === '') {
            $this->addError('restoreFile', 'The selected .rsc file is empty or could not be read.');

            return;
        }

        if (strlen($contents) > 61439) {
            $this->addError('restoreFile', 'RouterOS accepts .rsc files up to 61439 bytes.');

            return;
        }

        $fileName = 'mmutech-restore-'.$this->router->getKey().'-'.now()->format('YmdHis')
            .'-'.Str::lower(Str::random(6)).'.rsc';
        $path = 'router-imports/'.$fileName;

        if (! Storage::disk('local')->put($path, $contents)) {
            $this->addError('restoreFile', 'The uploaded script could not be staged for import. Try again.');

            return;
        }

        $originalName = (string) $this->restoreFile->getClientOriginalName();
        $job = $jobs->create(
            router: $this->router,
            type: 'restore_configuration',
            actor: auth()->user(),
            payload: [
                'file_name' => $fileName,
                'original_name' => $originalName,
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
            ],
        );

        $audit->record(
            'router.restore_queued',
            $this->router,
            metadata: [
                'job_uuid' => $job->uuid,
                'file_name' => $fileName,
                'original_name' => $originalName,
                'size' => strlen($contents),
            ],
            actor: auth()->user(),
        );

        try {
            RestoreRouterConfigurationJob::dispatch(
                routerId: $this->router->getKey(),
                routerJobId: $job->getKey(),
                fileName: $fileName,
                correlationId: $job->correlation_id,
            )->afterCommit();
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            $jobs->markFailed($job, $exception);
            $this->dispatchAlert('Restore failed to queue', $exception->getMessage(), 'error');

            return;
        }

        $this->restoreFile = null;
        $this->closeConfirmationDialog();
        $this->dispatchAlert(
            'Restore queued',
            'RouterOS will dry-run the .rsc file, import it, and verify the result.',
        );
    }

    public function enableHotspotCookieLogin(
        RouterJobService $jobs,
        AuditLogger $audit,
    ): void {
        $this->authorize('update', $this->router);

        $pendingJobExists = $this->router->jobs()
            ->whereIn('status', [
                RouterJobStatus::Queued->value,
                RouterJobStatus::Running->value,
                RouterJobStatus::Retrying->value,
            ])
            ->exists();

        if ($pendingJobExists) {
            $this->closeConfirmationDialog();
            $this->dispatchAlert(
                'Router operation in progress',
                'Wait for the current router operation to finish before enabling cookie-based login.',
                'error',
            );

            return;
        }

        $job = $jobs->create(
            router: $this->router,
            type: 'enable_hotspot_cookie_login',
            actor: auth()->user(),
            idempotencyKey: 'router-hotspot-cookie-login:'.$this->router->getKey(),
        );

        $audit->record(
            'router.hotspot_cookie_login_queued',
            $this->router,
            metadata: [
                'job_uuid' => $job->uuid,
                'cookie_lifetime' => '3650d',
            ],
            actor: auth()->user(),
        );

        EnableRouterHotspotCookieLoginJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        $this->closeConfirmationDialog();
        $this->dispatchAlert(
            'Cookie login queued',
            'RouterOS cookie-based HotSpot login has been queued. Voucher expiry will still disable the voucher on the router.',
        );
    }

    public function createRouterHotspotUserProfile(
        RouterJobService $jobs,
        AuditLogger $audit,
    ): void {
        $this->authorize('update', $this->router);

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
            $this->dispatchAlert(
                'Router operation in progress',
                'Wait for the current router operation to finish before creating a Hotspot profile.',
                'error',
            );

            return;
        }

        $uploadSpeedKbps = $validated['hotspotProfileUploadSpeedKbps'] ?? null;
        $downloadSpeedKbps = $validated['hotspotProfileDownloadSpeedKbps'] ?? null;
        $rateLimit = $uploadSpeedKbps === null || $uploadSpeedKbps === ''
            ? null
            : (int) $uploadSpeedKbps.'k/'.(int) $downloadSpeedKbps.'k';
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

        $this->dispatchAlert('HotSpot profile queued', 'Router-level Hotspot user profile creation has been queued.');
    }

    public function updateRouterHotspotUserProfile(
        RouterJobService $jobs,
        AuditLogger $audit,
    ): void {
        $this->authorize('update', $this->router);

        $profileId = $this->editingHotspotProfileId;
        $profileName = $this->editingHotspotProfileName;

        if ($profileId === null || $profileName === null) {
            $this->dispatchAlert(
                'Profile unavailable',
                'Refresh the router profile list before modifying this profile.',
                'error',
            );

            return;
        }

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

        if ($validated['hotspotProfileName'] !== $profileName) {
            $this->addError('hotspotProfileName', 'An existing profile cannot be renamed.');

            return;
        }

        if ($this->router->jobs()->whereIn('status', [
            RouterJobStatus::Queued->value,
            RouterJobStatus::Running->value,
            RouterJobStatus::Retrying->value,
        ])->exists()) {
            $this->dispatchAlert(
                'Router operation in progress',
                'Wait for the current router operation to finish before modifying a Hotspot profile.',
                'error',
            );

            return;
        }

        $uploadSpeedKbps = $validated['hotspotProfileUploadSpeedKbps'] ?? null;
        $downloadSpeedKbps = $validated['hotspotProfileDownloadSpeedKbps'] ?? null;
        $rateLimit = $uploadSpeedKbps === null || $uploadSpeedKbps === ''
            ? null
            : (int) $uploadSpeedKbps.'k/'.(int) $downloadSpeedKbps.'k';
        $configuration = [
            'profile_id' => $profileId,
            'profile_name' => $profileName,
            'expected_rate_limit' => $this->hotspotProfileExpectedRateLimit,
            'expected_shared_users' => (int) $this->hotspotProfileExpectedSharedUsers,
            'rate_limit' => $rateLimit,
            'shared_users' => (int) $validated['hotspotProfileSharedUsers'],
        ];
        $configurationHash = substr(hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)), 0, 32);

        $job = $jobs->create(
            router: $this->router,
            type: 'update_hotspot_user_profile',
            actor: auth()->user(),
            payload: $configuration,
            idempotencyKey: 'router-hotspot-user-profile-update:'.$this->router->getKey().':'.$profileId.':'.$configurationHash,
        );

        $audit->record(
            'router.hotspot_user_profile_update_queued',
            $this->router,
            metadata: [
                'job_uuid' => $job->uuid,
                'profile_id' => $profileId,
                'profile_name' => $profileName,
                'rate_limit' => $rateLimit,
                'shared_users' => $configuration['shared_users'],
            ],
            actor: auth()->user(),
        );

        UpdateRouterHotspotUserProfileJob::dispatch(
            routerId: $this->router->getKey(),
            routerJobId: $job->getKey(),
            correlationId: $job->correlation_id,
        )->afterCommit();

        $this->dispatchAlert('HotSpot profile update queued', 'The Hotspot user profile modification has been queued for RouterOS verification.');
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

    private function syncIdentityDerivedHotspotSettings(): bool
    {
        try {
            $identity = RouterIdentity::fromRouter($this->router);
            $this->hotspotServerName = $identity;
            $this->hotspotServerPool = $identity;

            return true;
        } catch (RouterProbeException $exception) {
            $this->hotspotServerName = '';
            $this->hotspotServerPool = '';
            $this->addError('hotspotServerConfiguration', $exception->getMessage());

            return false;
        }
    }

    /**
     * @return array<string, array<int, string|In>>
     */
    private function hotspotServerConfigurationRules(): array
    {
        return [
            'hotspotServerName' => ['required', 'string', 'max:'.RouterIdentity::MAX_LENGTH, 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'hotspotServerInterface' => ['required', 'string', Rule::in(array_column($this->hotspotServerInterfaces, 'name'))],
            'hotspotServerPool' => ['required', 'string', 'same:hotspotServerName', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'hotspotServerProfile' => ['required', 'string', Rule::in($this->hotspotServerProfiles)],
        ];
    }

    /** @return array<string, string> */
    private function hotspotServerConfigurationMessages(): array
    {
        return [
            'hotspotServerName.regex' => 'The router identity must be 1 to 64 characters and cannot contain control characters.',
            'hotspotServerInterface.in' => 'Select an interface discovered on this router.',
            'hotspotServerPool.same' => 'The address pool must match this router’s identity.',
            'hotspotServerPool.regex' => 'The address pool must be 1 to 64 characters and cannot contain control characters.',
            'hotspotServerProfile.in' => 'Select a HotSpot server profile discovered on this router.',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function hotspotServerConfigurationFromValidated(array $validated): array
    {
        return [
            'server_name' => $validated['hotspotServerName'],
            'interface' => $validated['hotspotServerInterface'],
            'address_pool' => $validated['hotspotServerPool'],
            'profile' => $validated['hotspotServerProfile'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function networkConfigurationRules(): array
    {
        return [
            'networkBridgeName' => ['required', 'string'],
            'wanInterface' => ['required', 'string'],
            'lanAddress' => ['required', 'string', 'max:18'],
            'poolStart' => ['required', 'string', 'max:15'],
            'poolEnd' => ['required', 'string', 'max:15'],
            'dnsServers' => ['required', 'string', 'max:500'],
            'managementCidrs' => ['required', 'string', 'max:2000'],
            'networkImpactConfirmed' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function networkConfigurationMessages(): array
    {
        return [
            'networkImpactConfirmed.accepted' => 'Confirm the network-impact warning before applying changes.',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function networkConfigurationFromValidated(array $validated): array
    {
        return [
            'bridge_name' => $validated['networkBridgeName'],
            'wan_interface' => $validated['wanInterface'],
            'lan_address' => trim($validated['lanAddress']),
            'pool_start' => trim($validated['poolStart']),
            'pool_end' => trim($validated['poolEnd']),
            'dns_servers' => $this->splitNetworkValues($validated['dnsServers']),
            'management_cidrs' => $this->splitNetworkValues($validated['managementCidrs']),
            'management_impact_confirmed' => $this->networkImpactConfirmed,
        ];
    }

    public function toggleMonitoring(RouterRegistryService $registry): void
    {
        $this->authorize('manageMonitoring', $this->router);

        $enabled = ! $this->router->monitoring_enabled;

        $registry->setMonitoring($this->router, $enabled, auth()->user());
        $this->router->refresh();

        $this->dispatchAlert(
            $enabled ? 'Monitoring enabled' : 'Monitoring disabled',
            $enabled
                ? 'Health monitoring enabled. The next scheduled run will probe this router.'
                : 'Health monitoring disabled. Existing history has been retained.',
        );
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
            $this->dispatchAlert('Access already exists', $user->name.' already has access to this router.', 'error');

            return;
        }

        $registry->grantAccess($this->router, $user, auth()->user());
        $this->router->load('users');
        $this->reset('selectedUserId');

        $this->dispatchAlert('Router access granted', 'Access granted to '.$user->name.'.');
    }

    public function revokeAccess(int $userId, RouterRegistryService $registry): void
    {
        $this->authorize('revokeAccess', $this->router);

        /** @var User $user */
        $user = $this->router->users()->whereKey($userId)->firstOrFail();

        $registry->revokeAccess($this->router, $user, auth()->user());
        $this->router->load('users');
        $this->closeConfirmationDialog();

        $this->dispatchAlert('Router access revoked', 'Access revoked for '.$user->name.'.');
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

        $this->dispatchAlert(
            'Router credentials updated',
            'Router credentials rotated. A read-only test has been queued.',
        );
    }

    public function archive(RouterRegistryService $registry): void
    {
        $this->authorize('delete', $this->router);

        $name = $this->router->name;
        $registry->archive($this->router, auth()->user());

        session()->flash('status', $name.' has been archived and removed from active monitoring.');
        $this->closeConfirmationDialog();

        $this->redirectRoute('routers.index', navigate: true);
    }

    private function markCurrentJobFailureAlertsShown(): void
    {
        foreach ([
            'network' => ['configure_network', 'verify_network'],
            'hotspot' => ['configure_hotspot'],
        ] as $key => $types) {
            $job = $this->router->jobs()
                ->whereIn('type', $types)
                ->where('status', RouterJobStatus::Failed)
                ->whereNotNull('error_message')
                ->latest('created_at')
                ->first();

            if ($job) {
                session()->put($this->jobFailureAlertSessionKey($key, $job), true);
            }
        }
    }

    private function shouldShowJobFailureAlert(string $key, ?RouterJob $job): bool
    {
        if (! $job || $job->status !== RouterJobStatus::Failed || ! $job->error_message) {
            return false;
        }

        $sessionKey = $this->jobFailureAlertSessionKey($key, $job);

        if (session()->has($sessionKey)) {
            return false;
        }

        session()->put($sessionKey, true);

        return true;
    }

    private function jobFailureAlertSessionKey(string $key, RouterJob $job): string
    {
        return 'router-job-failure-alert:'.$this->router->getKey().':'.$key.':'.$job->getKey();
    }

    private function routerHasPendingJob(): bool
    {
        return $this->router->jobs()
            ->whereIn('status', [
                RouterJobStatus::Queued->value,
                RouterJobStatus::Running->value,
                RouterJobStatus::Retrying->value,
            ])
            ->exists();
    }

    /**
     * Derive the MMUTech-managed reset scope from tracked router jobs.
     *
     * @return array{bridge_name: string, interfaces: array<int, string>, profile_names: array<int, string>}
     */
    private function managedResetScope(): array
    {
        $jobs = $this->router->jobs()
            ->whereIn('type', [
                'configure_bridge',
                'configure_network',
                'create_hotspot_user_profile',
                'update_hotspot_user_profile',
            ])
            ->orderBy('created_at')
            ->get();

        $bridgeName = '';
        $interfaces = [];
        $profileNames = [];

        foreach ($jobs as $job) {
            $payload = $job->payload ?? [];

            if ($job->type === 'configure_bridge') {
                $jobBridgeName = is_string($payload['bridge_name'] ?? null) ? $payload['bridge_name'] : '';

                if ($jobBridgeName !== '') {
                    $bridgeName = $jobBridgeName;
                }

                foreach ($payload['interfaces'] ?? [] as $interface) {
                    if (is_string($interface) && $interface !== '') {
                        $interfaces[$interface] = true;
                    }
                }

                foreach ($payload['remove_interfaces'] ?? [] as $interface) {
                    if (is_string($interface)) {
                        unset($interfaces[$interface]);
                    }
                }

                continue;
            }

            if ($job->type === 'configure_network') {
                $configuration = $payload['configuration'] ?? [];
                $jobBridgeName = is_string($configuration['bridge_name'] ?? null)
                    ? $configuration['bridge_name']
                    : '';

                if ($jobBridgeName !== '') {
                    $bridgeName = $jobBridgeName;
                }

                continue;
            }

            $profileName = is_string($payload['profile_name'] ?? null)
                ? $payload['profile_name']
                : (is_string($payload['configuration']['profile_name'] ?? null)
                    ? $payload['configuration']['profile_name']
                    : '');

            if ($profileName !== '') {
                $profileNames[$profileName] = true;
            }
        }

        return [
            'bridge_name' => $bridgeName !== '' ? $bridgeName : 'bridge-lan',
            'interfaces' => array_keys($interfaces),
            'profile_names' => array_keys($profileNames),
        ];
    }

    private function dispatchAlert(string $title, string $message, string $type = 'success'): void
    {
        $this->dispatch('app-alert', title: $title, message: $message, type: $type);
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
        $latestIdentityReportJob = $router->jobs()
            ->where('type', 'sync_identity')
            ->whereNotNull('payload->verification')
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
        $hotspotJobs = $router->jobs()
            ->where('type', 'configure_hotspot')
            ->latest('created_at')
            ->limit(5)
            ->get();
        $latestHotspotJob = $hotspotJobs->first();
        $latestHotspotReportJob = $hotspotJobs->first(
            fn ($job): bool => is_array($job->payload['verification'] ?? null)
        );
        $showNetworkFailureAlert = $this->shouldShowJobFailureAlert('network', $latestNetworkJob);
        $showHotspotFailureAlert = $this->shouldShowJobFailureAlert('hotspot', $latestHotspotJob);
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
            'latestIdentityReportJob' => $latestIdentityReportJob,
            'latestNetworkJob' => $latestNetworkJob,
            'latestNetworkReportJob' => $latestNetworkReportJob,
            'showNetworkFailureAlert' => $showNetworkFailureAlert,
            'canVerifyNetwork' => is_array($latestNetworkConfigurationJob?->payload['configuration'] ?? null),
            'latestHotspotJob' => $latestHotspotJob,
            'latestHotspotReportJob' => $latestHotspotReportJob,
            'showHotspotFailureAlert' => $showHotspotFailureAlert,
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
