<x-filament-panels::page>
    @php($user = $this->record)
    @php($ratio = $user->dataUsageRatio())

    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;">
        <div style="flex:1 1 18rem;min-width:0;">
            <x-filament::section>
                <x-slot name="heading">{{ __('Details') }}</x-slot>

                <div style="display:flex;flex-direction:column;gap:0.75rem;">
                    <div style="display:flex;flex-direction:column;gap:0.125rem;">
                        <span style="opacity:0.65;">{{ __('Service') }}</span>
                        <span>{{ $user->service->name }} ({{ $user->service->interface_name }})</span>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:0.125rem;">
                        <span style="opacity:0.65;">{{ __('Tunnel address') }}</span>
                        <span style="font-family:ui-monospace,monospace;">{{ $user->tunnel_ip }}</span>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:0.125rem;">
                        <span style="opacity:0.65;">{{ __('Status') }}</span>
                        <span style="display:inline-flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                            <x-filament::badge :color="$user->status->getColor()">
                                {{ $user->status->getLabel() }}
                            </x-filament::badge>
                            @if ($user->suspended_reason)
                                <span style="opacity:0.65;">{{ $user->suspended_reason }}</span>
                            @endif
                        </span>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:0.125rem;">
                        <span style="opacity:0.65;">{{ __('Logging') }}</span>
                        <span>{{ $user->loggingEffective() ? __('Recorded') : __('Not recorded') }}</span>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:0.125rem;">
                        <span style="opacity:0.65;">{{ __('Access expires') }}</span>
                        <span>{{ $user->expires_at?->toDayDateTimeString() ?? __('never') }}</span>
                    </div>
                    @if (filled($user->dns_override))
                        <div style="display:flex;flex-direction:column;gap:0.125rem;">
                            <span style="opacity:0.65;">{{ __('Custom DNS') }}</span>
                            <span style="font-family:ui-monospace,monospace;">{{ implode(', ', $user->dns_override) }}</span>
                        </div>
                    @endif
                    <div style="display:flex;flex-direction:column;gap:0.125rem;">
                        <span style="opacity:0.65;">{{ __('Speed limit') }}</span>
                        <span>{{ $user->rate_limit_kbps ? __(':speed Mbit/s (down + up)', ['speed' => round($user->rate_limit_kbps / 1000, 2)]) : __('unlimited') }}</span>
                    </div>
                    @if ($user->hasAccessSchedule())
                        <div style="display:flex;flex-direction:column;gap:0.125rem;">
                            <span style="opacity:0.65;">{{ __('Access schedule') }}</span>
                            <span>{{ $user->accessScheduleSummary() }}
                                @unless ($user->isWithinAccessWindow())
                                    <span style="opacity:0.65;">{{ __('(outside window now)') }}</span>
                                @endunless
                            </span>
                        </div>
                    @endif
                    @if ($user->max_connections)
                        <div style="display:flex;flex-direction:column;gap:0.125rem;">
                            <span style="opacity:0.65;">{{ __('Max devices') }}</span>
                            <span>{{ $user->max_connections }}</span>
                        </div>
                    @endif
                    @if ($user->labels)
                        <div style="display:flex;flex-direction:column;gap:0.125rem;">
                            <span style="opacity:0.65;">{{ __('Labels') }}</span>
                            <span style="display:flex;flex-wrap:wrap;gap:0.25rem;padding-top:0.25rem;">
                                @foreach ($user->labels as $label)
                                    <x-filament::badge color="gray">{{ $label }}</x-filament::badge>
                                @endforeach
                            </span>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        </div>

        <div style="flex:2 1 22rem;min-width:0;">
            <x-filament::section>
                <x-slot name="heading">{{ __('Usage') }}</x-slot>

                <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                    <div style="flex:1 1 10rem;display:flex;flex-direction:column;gap:0.125rem;">
                        <span style="opacity:0.65;">{{ __('Last handshake') }}</span>
                        <span>{{ $user->last_handshake_at?->diffForHumans() ?? __('never connected') }}</span>
                    </div>
                    <div style="flex:1 1 10rem;display:flex;flex-direction:column;gap:0.125rem;">
                        <span style="opacity:0.65;">{{ __('Last seen from') }}</span>
                        <span style="font-family:ui-monospace,monospace;">{{ $user->last_seen_ip ?? '--' }}</span>
                    </div>
                    <div style="flex:1 1 10rem;display:flex;flex-direction:column;gap:0.125rem;">
                        <span style="opacity:0.65;">{{ __('Traffic this window') }}</span>
                        <span>{{ $this->formatBytes($user->dataUsedBytes()) }}</span>
                    </div>
                </div>

                @if ($ratio !== null)
                    <div style="margin-top:1.25rem;">
                        <div style="display:flex;justify-content:space-between;gap:0.5rem;">
                            <span style="opacity:0.65;">{{ __('Allowance') }}</span>
                            <span>{{ __(':used of :total', ['used' => $this->formatBytes($user->dataUsedBytes()), 'total' => $this->formatBytes($user->data_limit_bytes)]) }}</span>
                        </div>
                        <div style="margin-top:0.5rem;height:0.5rem;width:100%;overflow:hidden;border-radius:9999px;background:rgba(128,128,128,0.2);">
                            <div style="height:100%;border-radius:9999px;width:{{ round($ratio * 100) }}%;background:{{ $ratio >= 0.9 ? 'rgb(var(--danger-500, 239 68 68))' : 'rgb(var(--primary-500, 59 130 246))' }};"></div>
                        </div>
                    </div>
                @endif
            </x-filament::section>
        </div>
    </div>

    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;">
        <div style="flex:1 1 20rem;min-width:0;">
            <x-filament::section>
                <x-slot name="heading">{{ __('Recent sessions') }}</x-slot>

                @php($sessions = $this->getRecentSessions())

                @if ($sessions === [])
                    <p style="opacity:0.65;">{{ __('No sessions recorded yet.') }}</p>
                @else
                    <div style="display:flex;flex-direction:column;">
                        @foreach ($sessions as $session)
                            <div style="display:flex;justify-content:space-between;gap:1rem;padding:0.5rem 0;border-top:1px solid rgba(128,128,128,0.15);">
                                <span>{{ $session['connected_at'] }}</span>
                                <span style="opacity:0.65;">
                                    {{ $session['ended'] }}
                                    @if ($session['peer_ip'])
                                        &middot; <span style="font-family:ui-monospace,monospace;">{{ $session['peer_ip'] }}</span>
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        </div>

        <div style="flex:1 1 20rem;min-width:0;">
            <x-filament::section>
                <x-slot name="heading">{{ __('Most visited (7 days)') }}</x-slot>

                @php($domains = $this->getTopDomains())

                @if ($domains === [])
                    <p style="opacity:0.65;">
                        @if ($user->loggingEffective())
                            {{ __("Nothing recorded yet. Their device has to be using the config this panel generates for its lookups to pass through this service's resolver.") }}
                        @else
                            {{ __('Logging is switched off for this user, so nothing they do is recorded.') }}
                        @endif
                    </p>
                @else
                    <div style="display:flex;flex-direction:column;">
                        @foreach ($domains as $domain)
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:0.5rem 0;border-top:1px solid rgba(128,128,128,0.15);">
                                <span style="display:inline-flex;align-items:center;gap:0.5rem;min-width:0;">
                                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $domain['host'] }}</span>
                                    <x-filament::badge :color="$domain['category']->getColor()" size="sm">
                                        {{ $domain['category']->getLabel() }}
                                    </x-filament::badge>
                                </span>
                                <span style="flex-shrink:0;opacity:0.65;">
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
