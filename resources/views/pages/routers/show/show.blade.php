@php
    $metadata = $router->metadata ?? [];
    $uptime = isset($metadata['uptime_seconds']) ? (int) $metadata['uptime_seconds'] : null;
    $uptimeLabel = $uptime === null
        ? null
        : sprintf('%dd %02dh %02dm', intdiv($uptime, 86400), intdiv($uptime % 86400, 3600), intdiv($uptime % 3600, 60));
@endphp

<div class="space-y-6">
    <div>
        <a href="{{ route('routers.index') }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-slate-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
            </svg>
            Back to routers
        </a>

        <div class="mt-4 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h1 class="truncate text-2xl font-bold tracking-tight text-slate-900">{{ $router->name }}</h1>
                    <span class="mm-badge {{ $router->status->badgeClasses() }}">{{ $router->status->label() }}</span>
                    @if ($router->monitoring_enabled)
                        <span class="mm-badge bg-emerald-50 text-emerald-700 ring-emerald-600/20">Monitoring active</span>
                    @else
                        <span class="mm-badge bg-slate-100 text-slate-600 ring-slate-500/20">Monitoring off</span>
                    @endif
                </div>
                <p class="mt-1.5 text-sm text-slate-500">
                    {{ $router->host }}:{{ $router->rest_port }} · {{ $router->isTls() ? 'HTTPS' : 'HTTP (lab only)' }}
                    @if ($router->site) · {{ $router->site }} @endif
                    @if ($router->location) · {{ $router->location }} @endif
                </p>
                <p class="mt-1 text-xs text-slate-400">
                    Public ID {{ $router->public_id }}
                    @if ($router->creator) · Registered by {{ $router->creator->name }} {{ $router->created_at?->diffForHumans() }} @endif
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($canTest)
                    <button
                        type="button"
                        wire:click="test"
                        wire:loading.attr="disabled"
                        wire:target="test"
                        @disabled($pendingJob !== null)
                        class="mm-btn-secondary"
                        title="{{ $pendingJob ? 'A router operation is already queued' : 'Queue a read-only connection test' }}"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 0 1 0 1.971l-11.54 6.347a1.125 1.125 0 0 1-1.667-.985V5.653Z" />
                        </svg>
                        Test connection
                    </button>
                @endif

                @if ($canEdit)
                    <button
                        type="button"
                        wire:click="provisionIdentity"
                        wire:loading.attr="disabled"
                        wire:target="provisionIdentity"
                        @disabled($pendingJob !== null)
                        class="mm-btn-secondary"
                        title="{{ $pendingJob ? 'A router operation is already queued' : 'Apply and verify the RouterOS identity' }}"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3Z" />
                        </svg>
                        Provision identity
                    </button>
                @endif

                @if ($canEdit)
                    <a href="{{ route('routers.edit', $router) }}" class="mm-btn-secondary">Edit router</a>
                @endif

                @if ($canArchive)
                    <button
                        type="button"
                        wire:click="openArchiveConfirmation"
                        wire:loading.attr="disabled"
                        wire:target="openArchiveConfirmation"
                        class="mm-btn-danger"
                    >
                        Archive
                    </button>
                @endif
            </div>
        </div>
    </div>

    @if ($pendingJob)
        <div class="flex items-start gap-3 rounded-2xl border border-brand-100 bg-brand-50 px-4 py-3 text-sm text-brand-700" role="status">
            <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <span>
                A {{ $pendingJob->type }} job is {{ $pendingJob->status->label() }} since {{ $pendingJob->created_at?->diffForHumans() }}.
                @if ($pendingJob->status === \App\Enums\RouterJobStatus::Queued)
                    A queue worker must be running for it to execute.
                @endif
            </span>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="mm-card overflow-hidden xl:col-span-2" aria-labelledby="router-facts-heading">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 id="router-facts-heading" class="text-base font-semibold text-slate-900">Verified router facts</h2>
                <p class="mt-0.5 text-xs text-slate-500">Read-only probe data and the latest identity verification</p>
            </div>
            @php
                $identityVerification = $latestIdentityReportJob && is_array($latestIdentityReportJob->payload['verification'] ?? null)
                    ? $latestIdentityReportJob->payload['verification']
                    : null;
            @endphp
            <dl class="grid gap-5 px-5 py-5 sm:grid-cols-2 sm:px-6">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Identity</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $router->identity ?: 'Not read yet' }}</dd>
                </div>
                <div class="sm:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <dt class="flex flex-wrap items-center justify-between gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <span>Latest identity provisioning</span>
                        @if ($identityVerification !== null)
                            <span class="mm-badge normal-case tracking-normal {{ ($identityVerification['status'] ?? '') === 'passed' ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-red-50 text-red-700 ring-red-600/20' }}">
                                {{ ($identityVerification['status'] ?? '') === 'passed' ? 'Verified' : 'Mismatch or failure' }}
                            </span>
                        @endif
                    </dt>
                    <dd class="mt-2 text-sm">
                        @if ($identityVerification === null)
                            <p class="text-xs text-slate-500">No identity provisioning verification yet.</p>
                        @else
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Expected</p>
                                    <p class="mt-1 break-words font-mono text-xs font-medium text-slate-900">{{ $identityVerification['expected'] ?? 'Not available' }}</p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Read back from RouterOS</p>
                                    <p class="mt-1 break-words font-mono text-xs font-medium text-slate-900">{{ $identityVerification['actual'] ?? 'Not available' }}</p>
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">{{ $identityVerification['verified_at'] ?? 'Time unavailable' }} · {{ $latestIdentityReportJob->status->label() }}</p>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">RouterOS version</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $router->ros_version ?: 'Not read yet' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Board</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $router->board_name ?: 'Not read yet' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Uptime</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $uptimeLabel ?? 'Not available' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">CPU load</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ isset($metadata['cpu_load']) ? $metadata['cpu_load'].'%' : 'Not available' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Memory</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">
                        @if (isset($metadata['free_memory'], $metadata['total_memory']) && (int) $metadata['total_memory'] > 0)
                            {{ \Illuminate\Support\Number::fileSize((int) $metadata['free_memory'], precision: 1) }} free of
                            {{ \Illuminate\Support\Number::fileSize((int) $metadata['total_memory'], precision: 1) }}
                        @else
                            Not available
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Last checked</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $router->last_checked_at?->format('j M Y, H:i:s') ?? 'Never' }} <span class="text-xs font-normal text-slate-500">{{ $router->last_checked_at?->diffForHumans() }}</span></dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Latency</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $router->latency_ms !== null ? $router->latency_ms.' ms' : 'Not available' }}</dd>
                </div>
            </dl>

            @if ($router->last_error_category)
                <div class="border-t border-red-100 bg-red-50 px-5 py-4 text-sm text-red-800 sm:px-6">
                    <p class="font-semibold">{{ $router->last_error_category->label() }}</p>
                    @if ($router->last_error_message)
                        <p class="mt-1 break-words text-xs leading-5">{{ $router->last_error_message }}</p>
                    @endif
                    <p class="mt-1 text-xs">{{ $router->consecutive_failures }} consecutive failure{{ $router->consecutive_failures === 1 ? '' : 's' }}.</p>
                </div>
            @endif
        </section>

        <section class="space-y-6">
            <div class="mm-card overflow-hidden">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-semibold text-slate-900">Credentials</h2>
                </div>
                <dl class="divide-y divide-slate-100 text-sm">
                    <div class="flex items-center justify-between gap-3 px-5 py-3">
                        <dt class="text-slate-500">API user</dt>
                        <dd class="font-mono text-xs font-medium text-slate-900">{{ $router->api_username }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-5 py-3">
                        <dt class="text-slate-500">Password</dt>
                        <dd class="font-mono text-xs font-medium text-slate-900">••••••••••••</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-5 py-3">
                        <dt class="text-slate-500">Last rotated</dt>
                        <dd class="text-xs font-medium text-slate-900">{{ $router->api_password_rotated_at?->format('j M Y, H:i') ?? 'Unknown' }}</dd>
                    </div>
                </dl>
                <div class="border-t border-slate-100 px-5 py-3">
                    <p class="text-xs leading-5 text-slate-500">The password is encrypted at rest and cannot be read back, even by a super administrator.</p>
                </div>
            </div>

            <div class="mm-card overflow-hidden">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-semibold text-slate-900">Monitoring</h2>
                </div>
                <div class="space-y-4 px-5 py-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-slate-900">Scheduled health checks</p>
                            <p class="mt-0.5 text-xs text-slate-500">Every five minutes when enabled</p>
                        </div>
                        @if ($canMonitor)
                            <button
                                type="button"
                                wire:click="toggleMonitoring"
                                wire:loading.attr="disabled"
                                wire:target="toggleMonitoring"
                                @class([
                                    'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2',
                                    'bg-success-600' => $router->monitoring_enabled,
                                    'bg-slate-300' => ! $router->monitoring_enabled,
                                ])
                                role="switch"
                                aria-checked="{{ $router->monitoring_enabled ? 'true' : 'false' }}"
                                aria-label="Toggle health monitoring"
                            >
                                <span @class([
                                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition',
                                    'translate-x-5' => $router->monitoring_enabled,
                                    'translate-x-0' => ! $router->monitoring_enabled,
                                ])></span>
                            </button>
                        @else
                            <span class="text-sm font-semibold {{ $router->monitoring_enabled ? 'text-emerald-700' : 'text-slate-500' }}">{{ $router->monitoring_enabled ? 'On' : 'Off' }}</span>
                        @endif
                    </div>
                    <div class="rounded-xl bg-slate-50 px-3 py-2.5 text-xs text-slate-600">
                        {{ $pendingJobCount }} open job{{ $pendingJobCount === 1 ? '' : 's' }} ·
                        {{ $usableVoucherCount }} usable voucher{{ $usableVoucherCount === 1 ? '' : 's' }} ·
                        {{ $provisioningSummary }}
                    </div>
                </div>
            </div>
        </section>
    </div>

    @if ($canEdit)
        <section class="mm-card overflow-hidden" aria-labelledby="bridge-heading">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 id="bridge-heading" class="text-base font-semibold text-slate-900">LAN bridge</h2>
                <p class="mt-1 text-sm text-slate-500">Create or reuse a bridge and add selected Ethernet interfaces. IP addresses, DHCP, firewall, and routing settings are not changed.</p>
            </div>

            <div class="space-y-5 px-5 py-5 sm:px-6">
                @error('bridgeConfiguration') <p class="mm-error">{{ $message }}</p> @enderror
                @error('lanInterfaces') <p class="mm-error">{{ $message }}</p> @enderror

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-slate-600">Discover the router's Ethernet ports before selecting which ones to bridge.</p>
                    <button
                        type="button"
                        wire:click="discoverLanInterfaces"
                        wire:loading.attr="disabled"
                        wire:target="discoverLanInterfaces"
                        class="mm-btn-secondary"
                    >
                        <span wire:loading.remove wire:target="discoverLanInterfaces">Discover Ethernet interfaces</span>
                        <span wire:loading wire:target="discoverLanInterfaces">Discovering…</span>
                    </button>
                </div>

                @if ($lanInterfacesDiscovered)
                    @if (count($lanInterfaces) === 0)
                        <p class="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">No physical Ethernet interfaces were returned by RouterOS.</p>
                    @else
                        <form wire:submit="applyBridgeConfiguration" class="space-y-5">
                            <div>
                                <label for="bridgeName" class="mm-label">Bridge name</label>
                                <input id="bridgeName" type="text" wire:model.live="bridgeName" required maxlength="32" class="mm-input" autocomplete="off">
                                @error('bridgeName') <p class="mm-error">{{ $message }}</p> @enderror
                            </div>

                            <fieldset>
                                <legend class="mm-label">Select LAN Ethernet interfaces</legend>
                                <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach ($lanInterfaces as $interface)
                                        @php
                                            $alreadyInTargetBridge = $interface['bridge'] === $bridgeName;
                                            $alreadyInAnotherBridge = $interface['bridge'] !== null && ! $alreadyInTargetBridge;
                                            $interfaceStatus = $interface['disabled'] ? 'Disabled' : ($interface['running'] ? 'Link up' : 'No link');
                                        @endphp
                                        <label wire:key="lan-interface-{{ $interface['name'] }}" @class([
                                            'flex items-start gap-3 rounded-xl border p-3 text-sm',
                                            'border-slate-200 hover:bg-slate-50' => ! $alreadyInAnotherBridge && ! $alreadyInTargetBridge,
                                            'border-emerald-200 bg-emerald-50' => $alreadyInTargetBridge,
                                            'border-slate-200 bg-slate-50 opacity-60' => $alreadyInAnotherBridge,
                                        ])>
                                            <input
                                                type="checkbox"
                                                wire:model.live="selectedLanInterfaces"
                                                value="{{ $interface['name'] }}"
                                                class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600"
                                                @disabled($alreadyInTargetBridge || $alreadyInAnotherBridge)
                                            >
                                            <span class="min-w-0">
                                                <span class="block font-mono font-semibold text-slate-800">{{ $interface['name'] }}</span>
                                                <span class="mt-0.5 block text-xs text-slate-500">
                                                    {{ $interfaceStatus }}
                                                    @if ($alreadyInTargetBridge)
                                                        · Already in {{ $bridgeName }}
                                                    @elseif ($alreadyInAnotherBridge)
                                                        · Member of {{ $interface['bridge'] }}
                                                    @endif
                                                </span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('selectedLanInterfaces') <p class="mm-error">{{ $message }}</p> @enderror
                                @if ($errors->has('selectedLanInterfaces.*'))
                                    <p class="mm-error">{{ $errors->first('selectedLanInterfaces.*') }}</p>
                                @endif
                            </fieldset>

                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="note">
                                <p class="font-semibold">Check WAN and management connectivity before applying.</p>
                                <p class="mt-1 text-xs leading-5">This change can interrupt access if you select the interface carrying the WAN or current management connection. MMUTech cannot determine your LAN/WAN roles automatically. Only the interfaces selected above will be added.</p>
                                <label class="mt-3 flex items-start gap-2.5">
                                    <input type="checkbox" wire:model.live="managementImpactConfirmed" value="1" class="mt-0.5 h-4 w-4 rounded border-amber-400 text-amber-700 focus:ring-amber-600">
                                    <span class="text-xs font-medium">I have checked the selected ports and understand this may disconnect the router.</span>
                                </label>
                                @error('managementImpactConfirmed') <p class="mm-error">{{ $message }}</p> @enderror
                            </div>

                            @if ($pendingJob)
                                <p class="text-sm text-amber-700">Wait for the current {{ str($pendingJob->type)->replace('_', ' ') }} job to finish before configuring the bridge.</p>
                            @elseif (count($selectedLanInterfaces) === 0)
                                <p class="text-sm text-slate-500">Select at least one available interface to continue.</p>
                            @elseif (! $managementImpactConfirmed)
                                <p class="text-sm text-slate-500">Confirm the connectivity warning to continue.</p>
                            @endif

                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    class="mm-btn-primary"
                                    wire:loading.attr="disabled"
                                    wire:target="applyBridgeConfiguration"
                                    @disabled(count($selectedLanInterfaces) === 0 || ! $managementImpactConfirmed || $pendingJob !== null)
                                >
                                    <span wire:loading.remove wire:target="applyBridgeConfiguration">Queue bridge configuration</span>
                                    <span wire:loading wire:target="applyBridgeConfiguration">Queueing…</span>
                                </button>
                            </div>
                        </form>
                    @endif
                @endif
            </div>
        </section>
    @endif

    @if ($canEdit)
        <section class="mm-card overflow-hidden" aria-labelledby="network-setup-heading">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 id="network-setup-heading" class="text-base font-semibold text-slate-900">DHCP, NAT &amp; firewall</h2>
                <p class="mt-1 text-sm text-slate-500">Configure LAN addressing and Internet sharing on the bridge above. All network values are required and are not pre-filled.</p>
            </div>

            <div class="space-y-5 px-5 py-5 sm:px-6">
                @error('networkConfiguration') <p class="mm-error">{{ $message }}</p> @enderror
                @error('networkVerification') <p class="mm-error">{{ $message }}</p> @enderror
                @error('networkCommands') <p class="mm-error">{{ $message }}</p> @enderror
                @error('networkDiscovery') <p class="mm-error">{{ $message }}</p> @enderror

                <div class="rounded-xl border border-slate-200">
                    <div class="flex flex-col gap-3 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">Current DHCP, NAT &amp; firewall configuration</h3>
                            <p class="mt-0.5 text-xs text-slate-500">Read-only discovery of existing RouterOS rules. Review it before queueing changes.</p>
                        </div>
                        <button
                            type="button"
                            wire:click="discoverNetworkConfiguration"
                            wire:loading.attr="disabled"
                            wire:target="discoverNetworkConfiguration"
                            class="mm-btn-secondary"
                        >
                            <span wire:loading.remove wire:target="discoverNetworkConfiguration">Discover settings</span>
                            <span wire:loading wire:target="discoverNetworkConfiguration">Discovering…</span>
                        </button>
                    </div>

                    @if ($networkConfigurationDiscovered)
                        <div class="divide-y divide-slate-200">
                            @foreach ([
                                'addresses' => 'IP addresses',
                                'pools' => 'IP pools',
                                'dhcp_networks' => 'DHCP networks',
                                'dhcp_servers' => 'DHCP servers',
                                'nat_rules' => 'NAT rules',
                                'firewall_rules' => 'Firewall filter rules',
                            ] as $discoveryKey => $discoveryLabel)
                                @php
                                    $discoveryRows = $networkDiscovery[$discoveryKey] ?? [];
                                @endphp
                                <div class="px-4 py-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $discoveryLabel }}</h4>
                                        <span class="text-xs text-slate-400">{{ count($discoveryRows) }}</span>
                                    </div>

                                    @if ($discoveryRows === [])
                                        <p class="mt-1 text-xs text-slate-500">None found.</p>
                                    @else
                                        <ul class="mt-2 divide-y divide-slate-100">
                                            @foreach ($discoveryRows as $discoveryRow)
                                                <li class="py-2">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="font-mono text-xs font-semibold text-slate-800">{{ $discoveryRow['title'] }}</span>
                                                        @if ($discoveryRow['managed'])
                                                            <span class="rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-semibold text-brand-700">MMUTech managed</span>
                                                        @endif
                                                        @if ($discoveryRow['disabled'])
                                                            <span class="rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-semibold text-red-700">Disabled</span>
                                                        @endif
                                                    </div>
                                                    @if ($discoveryRow['detail'] !== '')
                                                        <p class="mt-0.5 text-xs text-slate-500">{{ $discoveryRow['detail'] }}</p>
                                                    @endif
                                                    @if ($discoveryRow['comment'])
                                                        <p class="mt-0.5 break-words text-xs text-slate-400">{{ $discoveryRow['comment'] }}</p>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="px-4 py-3 text-sm text-slate-600">Discover the router's current DHCP, NAT, and firewall configuration before reviewing conflicts.</p>
                    @endif
                </div>

                @if (! $lanInterfacesDiscovered)
                    <p class="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">Discover Ethernet interfaces above before selecting the WAN port.</p>
                @endif

                <form wire:submit="configureNetwork" class="space-y-5">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label for="networkBridgeName" class="mm-label">LAN bridge</label>
                            <select id="networkBridgeName" wire:model.live="networkBridgeName" required class="mm-input">
                                <option value="">Select a discovered LAN bridge</option>
                                @foreach ($availableBridgeNames as $availableBridgeName)
                                    <option value="{{ $availableBridgeName }}">{{ $availableBridgeName }}</option>
                                @endforeach
                            </select>
                            @error('networkBridgeName') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="networkWanInterface" class="mm-label">WAN Ethernet interface</label>
                            <select id="networkWanInterface" wire:model.live="wanInterface" required class="mm-input">
                                <option value="">Select an unbridged Ethernet port</option>
                                @foreach ($lanInterfaces as $interface)
                                    @if ($interface['bridge'] === null)
                                        <option value="{{ $interface['name'] }}">{{ $interface['name'] }}{{ $interface['running'] ? ' · link up' : '' }}</option>
                                    @endif
                                @endforeach
                            </select>
                            @error('wanInterface') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="networkLanAddress" class="mm-label">LAN gateway address/prefix</label>
                            <input id="networkLanAddress" type="text" wire:model.live="lanAddress" required maxlength="18" class="mm-input" placeholder="IPv4 address/prefix">
                            @error('lanAddress') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="networkPoolStart" class="mm-label">DHCP pool start</label>
                            <input id="networkPoolStart" type="text" wire:model.live="poolStart" required maxlength="15" class="mm-input" placeholder="IPv4 address">
                            @error('poolStart') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="networkPoolEnd" class="mm-label">DHCP pool end</label>
                            <input id="networkPoolEnd" type="text" wire:model.live="poolEnd" required maxlength="15" class="mm-input" placeholder="IPv4 address">
                            @error('poolEnd') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="networkDnsServers" class="mm-label">DHCP DNS servers</label>
                            <textarea id="networkDnsServers" wire:model.live="dnsServers" required rows="2" class="mm-input" placeholder="IPv4 address(es), comma or newline separated"></textarea>
                            @error('dnsServers') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <div class="flex items-center justify-between gap-2">
                                <label for="networkManagementCidrs" class="mm-label">Management source CIDRs</label>
                                <button
                                    type="button"
                                    wire:click="discoverManagementSourceCidrs"
                                    wire:loading.attr="disabled"
                                    wire:target="discoverManagementSourceCidrs"
                                    class="text-xs font-semibold text-brand-700 hover:underline disabled:opacity-50"
                                >
                                    <span wire:loading.remove wire:target="discoverManagementSourceCidrs">Auto-detect</span>
                                    <span wire:loading wire:target="discoverManagementSourceCidrs">Detecting…</span>
                                </button>
                            </div>
                            <textarea id="networkManagementCidrs" wire:model.live="managementCidrs" rows="2" class="mm-input" placeholder="IPv4 CIDR(s), comma or newline separated"></textarea>
                            <p class="mm-help">Use /8 through /32 and include the MMUTech server's source network. Auto-detect resolves this server's source address as the router sees it.</p>
                            @if ($managementCidrDetectionNote !== '')
                                <p class="mm-help mt-1">{{ $managementCidrDetectionNote }}</p>
                            @endif
                            @error('managementCidrs') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="note">
                        <p class="font-semibold">Review the firewall impact before applying.</p>
                        <p class="mt-1 text-xs leading-5">The WAN must already have IPv4 addressing and a default route; MMUTech does not invent or configure upstream settings. MMUTech adds stateful LAN-to-WAN rules and blocks new unsolicited WAN traffic to the router and forwarded hosts, while keeping existing rules. The WAN input drop can interrupt VPNs or other services hosted on the router; existing rules may also affect policy order. Management CIDRs must include the MMUTech server's source address, or RouterOS API access may be lost. Review the report for rule-order and upstream-routing warnings.</p>
                        <label class="mt-3 flex items-start gap-2.5">
                            <input type="checkbox" wire:model.live="networkImpactConfirmed" value="1" class="mt-0.5 h-4 w-4 rounded border-amber-400 text-amber-700 focus:ring-amber-600">
                            <span class="text-xs font-medium">I verified the WAN, LAN, IP pool, DNS, and management networks and understand the firewall may interrupt existing services.</span>
                        </label>
                        @error('networkImpactConfirmed') <p class="mm-error">{{ $message }}</p> @enderror
                    </div>

                    @if ($pendingJob)
                        <p class="text-sm text-amber-700">Wait for the current {{ str($pendingJob->type)->replace('_', ' ') }} job to finish before configuring the network.</p>
                    @elseif (! $lanInterfacesDiscovered || $networkBridgeName === '' || $wanInterface === '')
                        <p class="text-sm text-slate-500">Discover interfaces and select an existing LAN bridge and an unbridged WAN port to continue.</p>
                    @elseif (! $networkImpactConfirmed)
                        <p class="text-sm text-slate-500">Confirm the network-impact warning to continue.</p>
                    @endif

                    <div class="flex flex-wrap justify-end gap-3">
                        <button
                            type="button"
                            class="mm-btn-secondary"
                            wire:click="generateNetworkCommands"
                            wire:loading.attr="disabled"
                            wire:target="generateNetworkCommands"
                            @disabled(! $lanInterfacesDiscovered || $networkBridgeName === '' || $wanInterface === '' || ! $networkImpactConfirmed || $pendingJob !== null)
                        >
                            <span wire:loading.remove wire:target="generateNetworkCommands">Generate terminal commands</span>
                            <span wire:loading wire:target="generateNetworkCommands">Generating…</span>
                        </button>
                        <button
                            type="submit"
                            class="mm-btn-primary"
                            wire:loading.attr="disabled"
                            wire:target="configureNetwork"
                            @disabled(! $lanInterfacesDiscovered || $networkBridgeName === '' || $wanInterface === '' || ! $networkImpactConfirmed || $pendingJob !== null)
                        >
                            <span wire:loading.remove wire:target="configureNetwork">Queue DHCP, NAT &amp; firewall configuration</span>
                            <span wire:loading wire:target="configureNetwork">Queueing…</span>
                        </button>
                    </div>
                </form>

                @if ($networkCommands !== [])
                    <div
                        x-data="{ copied: false, async copyCommands() { try { await navigator.clipboard.writeText(this.$refs.networkSetupCommands.innerText); this.copied = true; window.setTimeout(() => this.copied = false, 2000); } catch { window.dispatchEvent(new CustomEvent('app-alert', { detail: { title: 'Copy failed', message: 'Clipboard unavailable. Select and copy the commands manually.', type: 'error' } })); } } }"
                        class="space-y-3 rounded-xl border border-slate-200 p-4"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900">DHCP, NAT &amp; firewall terminal commands</h3>
                                <p class="text-xs text-slate-500">Manual alternative for the values entered above; these commands do not change the router until pasted into its terminal.</p>
                            </div>
                            <button type="button" @click="copyCommands" class="mm-btn-secondary">
                                <span x-text="copied ? 'Copied' : 'Copy commands'"></span>
                            </button>
                        </div>
                        <p class="text-xs leading-5 text-amber-800">These commands add new configuration and move the managed firewall rules to the top. Back up the router and review existing DHCP, NAT, and firewall rules first; repeated pastes can create duplicates or change existing policy order. The queued configuration button performs conflict checks and read-back verification.</p>
                        <pre x-ref="networkSetupCommands" class="overflow-x-auto rounded-xl bg-slate-950 p-4 text-xs leading-6 text-slate-100"><code>{{ implode("\n", $networkCommands) }}</code></pre>
                    </div>
                @endif

                @if ($latestNetworkJob?->status === \App\Enums\RouterJobStatus::Failed && $latestNetworkJob->error_message)
                    @include('partials.alert-modal', [
                        'title' => 'Router configuration failed',
                        'message' => $latestNetworkJob->error_message,
                        'type' => 'error',
                        'open' => $showNetworkFailureAlert,
                        'listen' => false,
                    ])
                @endif

                @if ($latestNetworkReportJob)
                    <div class="rounded-xl border border-slate-200">
                        <div class="flex flex-col gap-1 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900">Latest read-back verification</h3>
                                <p class="text-xs text-slate-500">{{ $latestNetworkReportJob->payload['verification_at'] ?? 'Time unavailable' }} · {{ $latestNetworkReportJob->status->label() }}</p>
                            </div>
                            @if ($canVerifyNetwork)
                                <button
                                    type="button"
                                    wire:click="verifyNetwork"
                                    wire:loading.attr="disabled"
                                    wire:target="verifyNetwork"
                                    class="mm-btn-secondary"
                                    @disabled($pendingJob !== null)
                                >
                                    <span wire:loading.remove wire:target="verifyNetwork">Verify again (read-only)</span>
                                    <span wire:loading wire:target="verifyNetwork">Queueing…</span>
                                </button>
                            @endif
                        </div>
                        <ul class="divide-y divide-slate-100">
                            @foreach ($latestNetworkReportJob->payload['verification'] as $name => $check)
                                <li class="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-start sm:justify-between">
                                    <span class="text-sm text-slate-700">{{ str($name)->replace('_', ' ')->title() }}</span>
                                    <span @class([
                                        'rounded-full px-2 py-1 text-xs font-semibold',
                                        'bg-emerald-50 text-emerald-700' => $check['status'] === 'passed',
                                        'bg-amber-50 text-amber-800' => $check['status'] === 'warning',
                                        'bg-red-50 text-red-700' => $check['status'] === 'failed',
                                    ])>{{ $check['status'] }} — {{ $check['message'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @elseif ($canVerifyNetwork)
                    <div class="flex flex-col gap-3 rounded-xl border border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-slate-600">A saved network configuration is available for read-only verification.</p>
                        <button
                            type="button"
                            wire:click="verifyNetwork"
                            wire:loading.attr="disabled"
                            wire:target="verifyNetwork"
                            class="mm-btn-secondary"
                            @disabled($pendingJob !== null)
                        >
                            Verify configuration
                        </button>
                    </div>
                @endif
            </div>
        </section>
    @endif

    @if ($canEdit)
        <section class="mm-card overflow-hidden" aria-labelledby="hotspot-server-heading">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 id="hotspot-server-heading" class="text-base font-semibold text-slate-900">HotSpot server</h2>
                <p class="mt-1 text-sm text-slate-500">Create or update the RouterOS HotSpot server on a LAN interface using the router identity as its name. A server with that name on the selected interface is updated; other existing servers are left unchanged.</p>
            </div>

            <div class="space-y-5 px-5 py-5 sm:px-6">
                @error('hotspotServerDiscovery') <p class="mm-error">{{ $message }}</p> @enderror
                @error('hotspotServerConfiguration') <p class="mm-error">{{ $message }}</p> @enderror
                @error('hotspotServerCommands') <p class="mm-error">{{ $message }}</p> @enderror

                <div class="flex flex-col gap-3 rounded-xl border border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Router HotSpot settings</h3>
                        <p class="mt-1 text-xs text-slate-500">Read interfaces, IP pools, server profiles, and existing HotSpot servers from RouterOS.</p>
                    </div>
                    <button
                        type="button"
                        wire:click="discoverHotspotServerSettings"
                        wire:loading.attr="disabled"
                        wire:target="discoverHotspotServerSettings"
                        class="mm-btn-secondary shrink-0"
                    >
                        <span wire:loading.remove wire:target="discoverHotspotServerSettings">Discover settings</span>
                        <span wire:loading wire:target="discoverHotspotServerSettings">Discovering…</span>
                    </button>
                </div>

                @if ($hotspotServers !== [])
                    <div class="overflow-hidden rounded-xl border border-slate-200">
                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-2.5">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-600">Existing HotSpot servers</h3>
                        </div>
                        @foreach ($hotspotServers as $server)
                            <div class="flex flex-col gap-1 border-b border-slate-100 px-4 py-3 last:border-b-0 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <span class="font-mono text-sm text-slate-900">{{ $server['name'] }}</span>
                                    @if ($server['name'] === $hotspotServerName)
                                        <span class="mm-badge ml-2 bg-brand-50 text-brand-700 ring-brand-600/20">Selected</span>
                                    @endif
                                </div>
                                <span class="text-xs text-slate-500">
                                    {{ $server['interface'] ?? 'no interface' }} · {{ $server['address_pool'] ?? 'no pool' }} · {{ $server['profile'] ?? 'no profile' }}{{ $server['disabled'] ? ' · disabled' : '' }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if (! $hotspotServerDiscovered)
                    <p class="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">Discover router settings above before configuring the HotSpot server.</p>
                @else
                    <form class="space-y-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <span class="mm-label">Server name</span>
                                <p id="hotspotServerName" class="mt-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-sm text-slate-900">{{ $hotspotServerName }}</p>
                                <p class="mt-1 text-xs text-slate-500">Derived from this router's identity.</p>
                                @error('hotspotServerName') <p class="mm-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="hotspotServerInterface" class="mm-label">Interface</label>
                                <select id="hotspotServerInterface" wire:model="hotspotServerInterface" required class="mm-input">
                                    <option value="">Select a router interface</option>
                                    @foreach ($hotspotServerInterfaces as $interface)
                                        <option value="{{ $interface['name'] }}">
                                            {{ $interface['name'] }} · {{ $interface['type'] ?: 'unknown' }}{{ $interface['running'] ? ' · link up' : '' }}{{ $interface['disabled'] ? ' · disabled' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('hotspotServerInterface') <p class="mm-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <span class="mm-label">Address pool <span class="ml-1 text-xs font-normal text-slate-500">[Locked]</span></span>
                                <p id="hotspotServerPool" class="mt-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-sm text-slate-900">{{ $hotspotServerPool }}</p>
                                <p class="mt-1 text-xs text-slate-500">Derived from this router's identity.</p>
                                @error('hotspotServerPool') <p class="mm-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="hotspotServerProfile" class="mm-label">Server profile</label>
                                <select id="hotspotServerProfile" wire:model="hotspotServerProfile" required class="mm-input">
                                    <option value="">Select a HotSpot server profile</option>
                                    @foreach ($hotspotServerProfiles as $profile)
                                        <option value="{{ $profile }}">{{ $profile }}</option>
                                    @endforeach
                                </select>
                                @error('hotspotServerProfile') <p class="mm-error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="note">
                            <p class="font-semibold">Review the HotSpot impact before applying.</p>
                            <p class="mt-1 text-xs leading-5">Clients on the selected interface will be redirected to the RouterOS HotSpot login page. The server name and address pool both come from this router's identity; make sure the identity-named pool was created by network provisioning and has enough addresses for voucher clients. The server profile controls login methods and cookie settings. Only a server named after the router identity on the selected interface will be updated; all other servers are left alone. The result is read back after the change. You will be asked to confirm before the job is queued.</p>
                        </div>

                        @if ($pendingJob)
                            <p class="text-sm text-amber-700">Wait for the current {{ str($pendingJob->type)->replace('_', ' ') }} job to finish before configuring the HotSpot server.</p>
                        @endif

                        <div class="flex flex-wrap justify-end gap-3">
                            <button
                                type="button"
                                class="mm-btn-secondary"
                                wire:click="generateHotspotServerCommands"
                                wire:loading.attr="disabled"
                                wire:target="generateHotspotServerCommands"
                            >
                                <span wire:loading.remove wire:target="generateHotspotServerCommands">Generate terminal commands</span>
                                <span wire:loading wire:target="generateHotspotServerCommands">Generating…</span>
                            </button>
                            <button
                                type="button"
                                class="mm-btn-primary"
                                wire:click="openHotspotServerConfirmation"
                                wire:loading.attr="disabled"
                                wire:target="openHotspotServerConfirmation"
                            >
                                Queue HotSpot server configuration
                            </button>
                        </div>
                    </form>

                    @if ($hotspotServerCommands !== [])
                        <div
                            x-data="{ copied: false, async copyCommands() { try { await navigator.clipboard.writeText(this.$refs.hotspotServerCommands.innerText); this.copied = true; window.setTimeout(() => this.copied = false, 2000); } catch { window.dispatchEvent(new CustomEvent('app-alert', { detail: { title: 'Copy failed', message: 'Clipboard unavailable. Select and copy the commands manually.', type: 'error' } })); } } }"
                            class="space-y-3 rounded-xl border border-slate-200 p-4"
                        >
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-900">HotSpot server terminal commands</h3>
                                    <p class="text-xs text-slate-500">Manual alternative for the values shown above; these commands do not change the router until pasted into its terminal.</p>
                                </div>
                                <button type="button" @click="copyCommands" class="mm-btn-secondary">
                                    <span x-text="copied ? 'Copied' : 'Copy commands'"></span>
                                </button>
                            </div>
                            <p class="text-xs leading-5 text-amber-800">Review existing HotSpot servers first. Repeated pastes can create duplicate servers; the queued configuration performs conflict checks and read-back verification.</p>
                            <pre x-ref="hotspotServerCommands" class="overflow-x-auto rounded-xl bg-slate-950 p-4 text-xs leading-6 text-slate-100"><code>{{ implode("\n", $hotspotServerCommands) }}</code></pre>
                        </div>
                    @endif
                @endif

                @if ($latestHotspotJob?->status === \App\Enums\RouterJobStatus::Failed && $latestHotspotJob->error_message)
                    @include('partials.alert-modal', [
                        'title' => 'HotSpot configuration failed',
                        'message' => $latestHotspotJob->error_message,
                        'type' => 'error',
                        'open' => $showHotspotFailureAlert,
                        'listen' => false,
                    ])
                @endif

                @if ($latestHotspotReportJob)
                    <div class="rounded-xl border border-slate-200">
                        <div class="border-b border-slate-200 px-4 py-3">
                            <h3 class="text-sm font-semibold text-slate-900">Latest HotSpot verification</h3>
                            <p class="text-xs text-slate-500">{{ $latestHotspotReportJob->payload['verification_at'] ?? 'Time unavailable' }} · {{ $latestHotspotReportJob->status->label() }}</p>
                        </div>
                        <ul class="divide-y divide-slate-100">
                            @foreach ($latestHotspotReportJob->payload['verification'] as $name => $check)
                                <li class="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-start sm:justify-between">
                                    <span class="text-sm text-slate-700">{{ str($name)->replace('_', ' ')->title() }}</span>
                                    <span @class([
                                        'rounded-full px-2 py-1 text-xs font-semibold',
                                        'bg-emerald-50 text-emerald-700' => $check['status'] === 'passed',
                                        'bg-red-50 text-red-700' => $check['status'] === 'failed',
                                    ])>{{ $check['status'] }} — {{ $check['message'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </section>
    @endif

    @if ($canEdit)
        <section class="mm-card overflow-hidden" aria-labelledby="hotspot-profiles-heading">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 id="hotspot-profiles-heading" class="text-base font-semibold text-slate-900">Hotspot user profiles</h2>
                <p class="mt-1 text-sm text-slate-500">Create user profiles on this router, then map each plan to an existing profile from the plan form. Voucher generation snapshots the selected mapping.</p>
            </div>

            <div class="space-y-4 px-5 py-5 sm:px-6">
                <div class="flex flex-col gap-3 rounded-xl border border-blue-100 bg-blue-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="max-w-2xl">
                        <h3 class="text-sm font-semibold text-slate-900">Remember voucher logins</h3>
                        <p class="mt-1 text-xs leading-5 text-slate-600">Enable RouterOS cookie login on this router’s HotSpot server profiles. Existing login methods stay enabled; the cookie lasts up to 3650 days, while voucher expiry still disables the router account.</p>
                    </div>
                    <button
                        type="button"
                        wire:click="openCookieLoginConfirmation"
                        wire:loading.attr="disabled"
                        wire:target="openCookieLoginConfirmation"
                        class="mm-btn-secondary shrink-0"
                        @disabled($pendingJob !== null)
                    >
                        <span wire:loading.remove wire:target="enableHotspotCookieLogin">Enable auto-login</span>
                        <span wire:loading wire:target="enableHotspotCookieLogin">Queueing…</span>
                    </button>
                </div>

                <p class="text-xs leading-5 text-slate-500">Optional rate limits are entered as upload and download kbps. Both must be set together; leave both blank for no rate cap.</p>

                <form wire:submit="createRouterHotspotUserProfile" class="space-y-4 rounded-xl border border-slate-200 p-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="hotspot-profile-name" class="mm-label">Profile name</label>
                            <input id="hotspot-profile-name" type="text" wire:model="hotspotProfileName" maxlength="64" required class="mm-input">
                            @error('hotspotProfileName') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="hotspot-profile-upload" class="mm-label">Upload limit (kbps)</label>
                            <input id="hotspot-profile-upload" type="number" wire:model="hotspotProfileUploadSpeedKbps" min="1" class="mm-input">
                            @error('hotspotProfileUploadSpeedKbps') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="hotspot-profile-download" class="mm-label">Download limit (kbps)</label>
                            <input id="hotspot-profile-download" type="number" wire:model="hotspotProfileDownloadSpeedKbps" min="1" class="mm-input">
                            @error('hotspotProfileDownloadSpeedKbps') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="hotspot-profile-shared-users" class="mm-label">Shared users</label>
                            <input id="hotspot-profile-shared-users" type="number" wire:model="hotspotProfileSharedUsers" min="1" required class="mm-input">
                            @error('hotspotProfileSharedUsers') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="createRouterHotspotUserProfile"
                        class="mm-btn-primary"
                        @disabled($pendingJob !== null)
                    >
                        <span wire:loading.remove wire:target="createRouterHotspotUserProfile">Queue profile creation</span>
                        <span wire:loading wire:target="createRouterHotspotUserProfile">Queueing…</span>
                    </button>
                </form>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Router profiles</h3>
                        <p class="text-xs text-slate-500">Refresh to read the current user profiles from RouterOS.</p>
                    </div>
                    <button
                        type="button"
                        wire:click="discoverRouterHotspotUserProfiles"
                        wire:loading.attr="disabled"
                        wire:target="discoverRouterHotspotUserProfiles"
                        class="mm-btn-secondary"
                    >
                        <span wire:loading.remove wire:target="discoverRouterHotspotUserProfiles">Refresh profiles</span>
                        <span wire:loading wire:target="discoverRouterHotspotUserProfiles">Refreshing…</span>
                    </button>
                </div>
                @if ($hotspotUserProfilesDiscovered)
                    <div class="overflow-hidden rounded-xl border border-slate-200">
                        @forelse ($hotspotUserProfiles as $profile)
                            <div class="flex flex-col gap-1 border-b border-slate-100 px-4 py-3 last:border-b-0 sm:flex-row sm:items-center sm:justify-between">
                                <span class="font-mono text-sm text-slate-900">{{ $profile['name'] }}</span>
                                <span class="text-xs text-slate-500">
                                    Rate limit: {{ $profile['rate_limit'] ?? 'none' }} · Shared users: {{ $profile['shared_users'] }}
                                </span>
                            </div>
                        @empty
                            <p class="px-4 py-3 text-sm text-slate-600">No Hotspot user profiles were found on this router.</p>
                        @endforelse
                    </div>
                @endif
            </div>
        </section>
    @endif

    @if ($canEdit)
        <section
            x-data="{ copied: false, async copyCommands() { try { await navigator.clipboard.writeText(this.$refs.routerSetupCommands.innerText); this.copied = true; window.setTimeout(() => this.copied = false, 2000); } catch { window.dispatchEvent(new CustomEvent('app-alert', { detail: { title: 'Copy failed', message: 'Clipboard unavailable. Select and copy the commands manually.', type: 'error' } })); } } }"
            class="mm-card overflow-hidden"
            aria-labelledby="router-certificate-heading"
        >
            <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <h2 id="router-certificate-heading" class="text-base font-semibold text-slate-900">Production HTTPS and API user</h2>
                    <p class="mt-1 text-sm text-slate-500">Run these commands in the RouterOS terminal, replacing each placeholder before pasting.</p>
                </div>
                <div class="shrink-0">
                    <button type="button" @click="copyCommands" class="mm-btn-secondary">
                        <span x-text="copied ? 'Copied' : 'Copy commands'"></span>
                    </button>
                </div>
            </div>

            <div class="space-y-4 px-5 py-5 sm:px-6">
                <p class="text-sm leading-6 text-slate-600">
                    Click Detect private IP and select the address MMUTech uses to reach the router.
                    Replace <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-xs">ALLOWED_MANAGEMENT_CIDRS</code> with the source CIDRs allowed to use www-ssl (include the app server and any admin network that needs WebFig),
                    <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-xs">MMUTECH_SERVER_CIDR</code> with the app server's source IP/CIDR as seen by the router,
                    and <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-xs">UNIQUE_STRONG_PASSWORD</code> with a new unique password.
                    The dedicated user is limited to REST API read/write access and the specified source network.
                </p>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <label for="certificatePrivateIp" class="mm-label">Private IPv4 address for certificate</label>
                        <select id="certificatePrivateIp" wire:model.live="certificatePrivateIp" class="mm-input">
                            <option value="">Detect or select a private IP</option>
                            @foreach ($privateIpv4Addresses as $address)
                                <option value="{{ $address['ip'] }}">
                                    {{ $address['ip'] }}{{ $address['interface'] ? ' · '.$address['interface'] : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('privateIpv4Addresses') <p class="mm-error">{{ $message }}</p> @enderror
                        @if ($privateIpv4DiscoveryComplete && $privateIpv4Addresses === [])
                            <p class="mm-help">No enabled RFC 1918 IPv4 address was found. Enter the private management IP manually in the command before pasting.</p>
                        @elseif (! $privateIpv4DiscoveryComplete && ! $errors->has('privateIpv4Addresses'))
                            <p class="mm-help">RouterOS is queried only when you click Detect private IP.</p>
                        @elseif (count($privateIpv4Addresses) > 1 && $certificatePrivateIp === '')
                            <p class="mm-help">Multiple private IPs were found. Select the one MMUTech uses to reach this router.</p>
                        @endif
                    </div>
                    <button
                        type="button"
                        wire:click="discoverPrivateIpv4Addresses"
                        wire:loading.attr="disabled"
                        wire:target="discoverPrivateIpv4Addresses"
                        class="mm-btn-secondary"
                    >
                        <span wire:loading.remove wire:target="discoverPrivateIpv4Addresses">Detect private IP</span>
                        <span wire:loading wire:target="discoverPrivateIpv4Addresses">Detecting…</span>
                    </button>
                </div>

                <pre x-ref="routerSetupCommands" class="overflow-x-auto rounded-xl bg-slate-950 p-4 text-xs leading-6 text-slate-100"><code>/certificate add name=router-cert common-name={{ $certificatePrivateIp !== '' ? $certificatePrivateIp : '<ROUTER_PRIVATE_IP>' }} key-size=2048 days-valid=3650
/certificate sign router-cert name=router-cert
/ip service set www-ssl certificate=router-cert address=&lt;ALLOWED_MANAGEMENT_CIDRS&gt;
/ip service enable www-ssl
/certificate print detail
/user group add name=mmutech-api policy=read,write,rest-api
/user add name=mmutech-api group=mmutech-api address=&lt;MMUTECH_SERVER_CIDR&gt; password="&lt;UNIQUE_STRONG_PASSWORD&gt;"</code></pre>

                <p class="text-xs leading-5 text-amber-800">
                    The certificate generated by these commands is self-signed. For production, use a certificate trusted by the MMUTech host or install the issuing CA in its trust store; keep TLS verification enabled. Use the new username and password when registering or editing this router in MMUTech.
                </p>
            </div>
        </section>
    @endif

    @if ($canEdit)
        <section class="mm-card overflow-hidden" aria-labelledby="rotate-heading">
            <details>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 sm:px-6">
                    <div>
                        <h2 id="rotate-heading" class="text-base font-semibold text-slate-900">Rotate router credentials</h2>
                        <p class="mt-0.5 text-xs text-slate-500">Replace the API user or password. Existing values are never shown.</p>
                    </div>
                    <svg class="h-5 w-5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </summary>

                <form wire:submit="rotateCredentials" class="space-y-5 border-t border-slate-200 px-5 py-5 sm:px-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="new_api_username" class="mm-label">RouterOS API user</label>
                            <input id="new_api_username" type="text" wire:model="new_api_username" required maxlength="120" autocomplete="off" class="mm-input font-mono">
                            @error('new_api_username') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="new_api_password" class="mm-label">New API password</label>
                            <input id="new_api_password" type="password" wire:model="new_api_password" required autocomplete="new-password" class="mm-input" placeholder="At least 8 characters">
                            @error('new_api_password') <p class="mm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="new_api_password_confirmation" class="mm-label">Confirm new password</label>
                            <input id="new_api_password_confirmation" type="password" wire:model="new_api_password_confirmation" required autocomplete="new-password" class="mm-input">
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="mm-btn-primary" wire:loading.attr="disabled" wire:target="rotateCredentials">
                            <span wire:loading.remove wire:target="rotateCredentials">Rotate and test</span>
                            <span wire:loading wire:target="rotateCredentials">Rotating…</span>
                        </button>
                    </div>
                </form>
            </details>
        </section>
    @endif

    @if ($canGrant || $canRevoke)
        <section class="mm-card overflow-hidden" aria-labelledby="access-heading">
            <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <h2 id="access-heading" class="text-base font-semibold text-slate-900">Explicit router access</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Only super administrators can add or remove access. Roles alone do not grant router visibility.</p>
                </div>
            </div>

            <div class="divide-y divide-slate-100">
                @forelse ($router->users as $member)
                    <div class="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ $member->name }} @if ($member->id === auth()->id())<span class="text-xs font-normal text-slate-500">(you)</span>@endif</p>
                            <p class="truncate text-xs text-slate-500">{{ $member->email }} · {{ implode(', ', $member->roleLabels()) ?: 'No role' }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-slate-500">Granted {{ $member->pivot?->created_at?->diffForHumans() ?? 'previously' }}</span>
                            @if ($canRevoke)
                                <button
                                    type="button"
                                    wire:click="openRevokeAccessConfirmation({{ $member->getKey() }})"
                                    wire:loading.attr="disabled"
                                    class="rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50"
                                >
                                    Revoke
                                </button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-5 text-sm text-slate-500 sm:px-6">No explicit access assignments. Only super administrators can currently reach this router.</p>
                @endforelse
            </div>

            @if ($canGrant)
                <form wire:submit="grantAccess" class="border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="flex-1">
                            <label for="selectedUserId" class="mm-label">Grant access to staff member</label>
                            <select id="selectedUserId" wire:model="selectedUserId" class="mm-input">
                                <option value="">Choose a staff member</option>
                                @foreach ($staff as $member)
                                    <option value="{{ $member->id }}">{{ $member->name }} — {{ $member->email }}</option>
                                @endforeach
                            </select>
                            @error('selectedUserId') <p class="mm-error">{{ $message }}</p> @enderror
                            @if ($staff->isEmpty())
                                <p class="mm-help">Every eligible staff member already has access.</p>
                            @endif
                        </div>
                        <button type="submit" class="mm-btn-primary" @disabled($staff->isEmpty())>Grant access</button>
                    </div>
                </form>
            @endif
        </section>
    @endif

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="mm-card overflow-hidden" aria-labelledby="health-heading">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 id="health-heading" class="text-base font-semibold text-slate-900">Health history</h2>
                <p class="mt-0.5 text-xs text-slate-500">Last eight read-only probes</p>
            </div>
            @if ($healthChecks->isEmpty())
                <p class="px-5 py-6 text-sm text-slate-500 sm:px-6">No health checks recorded. Queue a connection test to create the first record.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th scope="col" class="mm-th">Result</th>
                                <th scope="col" class="mm-th">Latency</th>
                                <th scope="col" class="mm-th">Checked</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($healthChecks as $check)
                                <tr>
                                    <td class="mm-td">
                                        <span @class([
                                            'mm-badge',
                                            'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $check->status->isSuccess(),
                                            'bg-red-50 text-red-700 ring-red-600/20' => ! $check->status->isSuccess(),
                                        ])>{{ $check->status->label() }}</span>
                                        @if ($check->error_category)
                                            <p class="mt-1 text-xs text-red-600">{{ $check->error_category }}</p>
                                        @endif
                                    </td>
                                    <td class="mm-td">{{ $check->latency_ms !== null ? $check->latency_ms.' ms' : '—' }}</td>
                                    <td class="mm-td text-xs text-slate-500">{{ $check->checked_at?->format('j M H:i:s') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="mm-card overflow-hidden" aria-labelledby="jobs-heading">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 id="jobs-heading" class="text-base font-semibold text-slate-900">Router jobs</h2>
                <p class="mt-0.5 text-xs text-slate-500">Queued operations and their outcomes</p>
            </div>
            @if ($jobs->isEmpty())
                <p class="px-5 py-6 text-sm text-slate-500 sm:px-6">No router jobs yet.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($jobs as $job)
                        <li class="flex items-start justify-between gap-3 px-5 py-3 sm:px-6">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900">{{ str($job->type)->replace('_', ' ')->title() }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $job->created_at?->format('j M Y, H:i:s') }}
                                    @if ($job->attempts > 0) · {{ $job->attempts }} attempt{{ $job->attempts === 1 ? '' : 's' }} @endif
                                </p>
                                @if ($job->error_message)
                                    <p class="mt-1 break-words text-xs text-red-600">{{ $job->error_message }}</p>
                                @endif
                            </div>
                            <span @class([
                                'mm-badge shrink-0',
                                'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $job->status === \App\Enums\RouterJobStatus::Succeeded,
                                'bg-red-50 text-red-700 ring-red-600/20' => $job->status === \App\Enums\RouterJobStatus::Failed,
                                'bg-brand-50 text-brand-700 ring-brand-600/20' => $job->status->isPending(),
                                'bg-slate-100 text-slate-600 ring-slate-500/20' => $job->status === \App\Enums\RouterJobStatus::Cancelled,
                            ])>{{ $job->status->label() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($confirmationDialog !== '')
            <div class="fixed inset-0 z-[60] flex items-end justify-center bg-slate-950/60 p-0 backdrop-blur-sm sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-label="Confirm router action">
                <button type="button" class="absolute inset-0 h-full w-full cursor-default" wire:click="closeConfirmationDialog" aria-label="Cancel action"></button>
                <div class="relative z-10 w-full rounded-t-3xl bg-white p-5 shadow-2xl sm:max-w-lg sm:rounded-3xl sm:p-6">
                    <h2 class="text-lg font-semibold text-slate-900">
                        @if ($confirmationDialog === 'archive')
                            Archive router?
                        @elseif ($confirmationDialog === 'cookie_login')
                            Enable cookie-based login?
                        @elseif ($confirmationDialog === 'hotspot_server')
                            Configure HotSpot server?
                        @else
                            Revoke router access?
                        @endif
                    </h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600">
                        @if ($confirmationDialog === 'archive')
                            Archive {{ $router->name }}? It will be removed from active monitoring. The record is retained and can be restored by an administrator.
                        @elseif ($confirmationDialog === 'cookie_login')
                            Enable cookie-based login on all HotSpot server profiles? Existing login methods will remain enabled.
                        @elseif ($confirmationDialog === 'hotspot_server')
                            Create or update the HotSpot server {{ $hotspotServerName }} on {{ $hotspotServerInterface }} using the {{ $hotspotServerPool }} address pool and the {{ $hotspotServerProfile }} profile? The server name and address pool both come from this router's identity. A matching server on that interface will be updated and read back.
                        @else
                            Revoke {{ $router->users->firstWhere('id', $confirmationMemberId)?->name ?? 'this staff member' }}'s access to {{ $router->name }}?
                        @endif
                    </p>
                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" wire:click="closeConfirmationDialog" class="mm-btn-secondary">Cancel</button>
                        @if ($confirmationDialog === 'archive')
                            <button type="button" wire:click="archive" wire:loading.attr="disabled" class="mm-btn-danger">Archive</button>
                        @elseif ($confirmationDialog === 'cookie_login')
                            <button type="button" wire:click="enableHotspotCookieLogin" wire:loading.attr="disabled" class="mm-btn-primary">Enable auto-login</button>
                        @elseif ($confirmationDialog === 'hotspot_server')
                            <button type="button" wire:click="configureHotspotServer" wire:loading.attr="disabled" class="mm-btn-primary">Queue configuration</button>
                        @else
                            <button type="button" wire:click="revokeAccess({{ $confirmationMemberId }})" wire:loading.attr="disabled" class="mm-btn-danger">Revoke access</button>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>

    <section class="mm-card overflow-hidden" aria-labelledby="api-log-heading">
        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
            <h2 id="api-log-heading" class="text-base font-semibold text-slate-900">Recent RouterOS API calls</h2>
            <p class="mt-0.5 text-xs text-slate-500">Endpoint, outcome, and latency only. Request and response bodies are not displayed.</p>
        </div>
        @if ($apiLogs->isEmpty())
            <p class="px-5 py-6 text-sm text-slate-500 sm:px-6">No API calls recorded yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="mm-th">Endpoint</th>
                            <th scope="col" class="mm-th">HTTP</th>
                            <th scope="col" class="mm-th">Latency</th>
                            <th scope="col" class="mm-th">Result</th>
                            <th scope="col" class="mm-th">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($apiLogs as $log)
                            <tr>
                                <td class="mm-td"><span class="font-mono text-xs text-slate-700">{{ $log->method }} {{ $log->endpoint }}</span></td>
                                <td class="mm-td text-xs text-slate-600">{{ $log->http_status ?? '—' }}</td>
                                <td class="mm-td text-xs text-slate-600">{{ $log->latency_ms }} ms</td>
                                <td class="mm-td">
                                    <span @class([
                                        'mm-badge',
                                        'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $log->success,
                                        'bg-red-50 text-red-700 ring-red-600/20' => ! $log->success,
                                    ])>{{ $log->success ? 'Success' : ($log->error_category ? str($log->error_category)->replace('_', ' ')->title() : 'Failed') }}</span>
                                    @if ($log->error_message)
                                        <p class="mt-1 max-w-xs truncate text-xs text-red-600" title="{{ $log->error_message }}">{{ $log->error_message }}</p>
                                    @endif
                                </td>
                                <td class="mm-td text-xs text-slate-500">{{ $log->created_at?->format('j M H:i:s') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

</div>
