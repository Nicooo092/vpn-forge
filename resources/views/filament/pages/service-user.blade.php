<x-filament-panels::page>
    @php($user = $this->record)
    @php($ratio = $user->dataUsageRatio())

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div>
            <x-filament::section>
                <x-slot name="heading">{{ __('Details') }}</x-slot>

                <div class="flex flex-col gap-3">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Service') }}</span>
                        <span>{{ $user->service->name }} ({{ $user->service->interface_name }})</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Tunnel address') }}</span>
                        <span class="font-mono">{{ $user->tunnel_ip }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Status') }}</span>
                        <span class="inline-flex flex-wrap items-center gap-2">
                            <x-filament::badge :color="$user->status->getColor()">
                                {{ $user->status->getLabel() }}
                            </x-filament::badge>
                            @if ($user->suspended_reason)
                                {{-- suspended_reason is stored in English by the enforcement
                                     jobs, so it is translated here at the display site rather
                                     than on the way into the database. --}}
                                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __($user->suspended_reason) }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Logging') }}</span>
                        <span>{{ $user->loggingEffective() ? __('Recorded') : __('Not recorded') }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Access expires') }}</span>
                        <span>{{ $user->expires_at?->toDayDateTimeString() ?? __('never') }}</span>
                    </div>
                    @if (filled($user->dns_override))
                        <div class="flex flex-col gap-0.5">
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Custom DNS') }}</span>
                            <span class="font-mono">{{ implode(', ', $user->dns_override) }}</span>
                        </div>
                    @endif
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Speed limit') }}</span>
                        <span>{{ $user->rate_limit_kbps ? __(':speed Mbit/s (down + up)', ['speed' => round($user->rate_limit_kbps / 1000, 2)]) : __('unlimited') }}</span>
                    </div>
                    @if ($user->hasAccessSchedule())
                        <div class="flex flex-col gap-0.5">
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Access schedule') }}</span>
                            <span>{{ $user->accessScheduleSummary() }}
                                @unless ($user->isWithinAccessWindow())
                                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('(outside window now)') }}</span>
                                @endunless
                            </span>
                        </div>
                    @endif
                    @if ($user->max_connections)
                        <div class="flex flex-col gap-0.5">
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Max devices') }}</span>
                            <span>{{ $user->max_connections }}</span>
                        </div>
                    @endif
                    @if ($user->labels)
                        <div class="flex flex-col gap-0.5">
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Labels') }}</span>
                            <span class="flex flex-wrap gap-1 pt-1">
                                @foreach ($user->labels as $label)
                                    <x-filament::badge color="gray">{{ $label }}</x-filament::badge>
                                @endforeach
                            </span>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        </div>

        <div class="lg:col-span-2">
            <x-filament::section>
                <x-slot name="heading">{{ __('Usage') }}</x-slot>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Last handshake') }}</span>
                        <span>{{ $user->last_handshake_at?->diffForHumans() ?? __('never connected') }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Last seen from') }}</span>
                        <span class="font-mono">{{ $user->last_seen_ip ?? '--' }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Traffic this window') }}</span>
                        <span>{{ $this->formatBytes($user->dataUsedBytes()) }}</span>
                    </div>
                </div>

                @if ($ratio !== null)
                    <div class="mt-5">
                        <div class="flex justify-between gap-2">
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Allowance') }}</span>
                            <span>{{ __(':used of :total', ['used' => $this->formatBytes($user->dataUsedBytes()), 'total' => $this->formatBytes($user->data_limit_bytes)]) }}</span>
                        </div>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                            {{-- vf-gauge-fill sweeps the bar out from its left
                                 edge on arrival; the width stays the source of
                                 truth and the animation only scales it. --}}
                            <div
                                class="vf-gauge-fill h-full rounded-full {{ $ratio >= 0.9 ? 'bg-danger-500' : 'bg-primary-500' }}"
                                style="width: {{ round($ratio * 100) }}%"
                            ></div>
                        </div>
                    </div>
                @endif
            </x-filament::section>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div>
            <x-filament::section>
                <x-slot name="heading">{{ __('Recent sessions') }}</x-slot>

                @php($sessions = $this->getRecentSessions())

                @if ($sessions === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No sessions recorded yet.') }}</p>
                @else
                    <div class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($sessions as $session)
                            <div class="flex items-center justify-between gap-4 py-2">
                                <span>{{ $session['connected_at'] }}</span>
                                <span class="shrink-0 text-sm text-gray-500 dark:text-gray-400">
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
        </div>

        <div>
            <x-filament::section>
                <x-slot name="heading">{{ __('Most visited (7 days)') }}</x-slot>

                @php($domains = $this->getTopDomains())

                @if ($domains === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        @if ($user->loggingEffective())
                            {{ __("Nothing recorded yet. Their device has to be using the config this panel generates for its lookups to pass through this service's resolver.") }}
                        @else
                            {{ __('Logging is switched off for this user, so nothing they do is recorded.') }}
                        @endif
                    </p>
                @else
                    <div class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($domains as $domain)
                            <div class="flex items-center justify-between gap-4 py-2">
                                <span class="inline-flex min-w-0 items-center gap-2">
                                    <span class="truncate">{{ $domain['host'] }}</span>
                                    <x-filament::badge :color="$domain['category']->getColor()" size="sm">
                                        {{ $domain['category']->getLabel() }}
                                    </x-filament::badge>
                                </span>
                                <span class="shrink-0 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $domain['hits'] }} &middot; {{ $domain['last_seen'] }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
