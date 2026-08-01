<x-filament-panels::page>
    @php($user = $this->record)
    @php($ratio = $user->dataUsageRatio())

    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section class="lg:col-span-1">
            <x-slot name="heading">Details</x-slot>

            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Service</dt>
                    <dd>{{ $user->service->name }} ({{ $user->service->interface_name }})</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Tunnel address</dt>
                    <dd class="font-mono">{{ $user->tunnel_ip }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Status</dt>
                    <dd>
                        <x-filament::badge :color="$user->status->getColor()" class="inline-flex">
                            {{ $user->status->getLabel() }}
                        </x-filament::badge>
                        @if ($user->suspended_reason)
                            <span class="text-gray-500 dark:text-gray-400">{{ $user->suspended_reason }}</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Logging</dt>
                    <dd>{{ $user->loggingEffective() ? 'Recorded' : 'Not recorded' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Access expires</dt>
                    <dd>{{ $user->expires_at?->toDayDateTimeString() ?? 'never' }}</dd>
                </div>
                @if ($user->labels)
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Labels</dt>
                        <dd class="flex flex-wrap gap-1 pt-1">
                            @foreach ($user->labels as $label)
                                <x-filament::badge color="gray">{{ $label }}</x-filament::badge>
                            @endforeach
                        </dd>
                    </div>
                @endif
            </dl>
        </x-filament::section>

        <x-filament::section class="lg:col-span-2">
            <x-slot name="heading">Usage</x-slot>

            <dl class="grid gap-4 sm:grid-cols-3 text-sm">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Last handshake</dt>
                    <dd>{{ $user->last_handshake_at?->diffForHumans() ?? 'never connected' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Last seen from</dt>
                    <dd class="font-mono">{{ $user->last_seen_ip ?? '--' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Traffic this window</dt>
                    <dd>{{ $this->formatBytes($user->dataUsedBytes()) }}</dd>
                </div>
            </dl>

            @if ($ratio !== null)
                <div class="mt-5">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500 dark:text-gray-400">Allowance</span>
                        <span>{{ $this->formatBytes($user->dataUsedBytes()) }} of {{ $this->formatBytes($user->data_limit_bytes) }}</span>
                    </div>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                        <div
                            @class([
                                'h-full rounded-full',
                                'bg-primary-500' => $ratio < 0.9,
                                'bg-danger-500' => $ratio >= 0.9,
                            ])
                            style="width: {{ round($ratio * 100) }}%"
                        ></div>
                    </div>
                </div>
            @endif
        </x-filament::section>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Recent sessions</x-slot>

            @php($sessions = $this->getRecentSessions())

            @if ($sessions === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">No sessions recorded yet.</p>
            @else
                <div class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                    @foreach ($sessions as $session)
                        <div class="flex justify-between gap-4 py-2">
                            <span>{{ $session['connected_at'] }}</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $session['ended'] }}
                                @if ($session['peer_ip'])
                                    &middot; <span class="font-mono">{{ $session['peer_ip'] }}</span>
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Most visited (7 days)</x-slot>

            @php($domains = $this->getTopDomains())

            @if ($domains === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if ($user->loggingEffective())
                        Nothing recorded yet. Their device has to be using the config this panel generates for
                        its lookups to pass through this service's resolver.
                    @else
                        Logging is switched off for this user, so nothing they do is recorded.
                    @endif
                </p>
            @else
                <div class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                    @foreach ($domains as $domain)
                        <div class="flex items-center justify-between gap-4 py-2">
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="truncate">{{ $domain['host'] }}</span>
                                <x-filament::badge :color="$domain['category']->getColor()" size="sm">
                                    {{ $domain['category']->getLabel() }}
                                </x-filament::badge>
                            </span>
                            <span class="shrink-0 text-gray-500 dark:text-gray-400">
                                {{ $domain['hits'] }} &middot; {{ $domain['last_seen'] }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
