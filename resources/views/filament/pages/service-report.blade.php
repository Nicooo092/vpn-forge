<x-filament-panels::page>
    <x-filament::section compact>
        <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
            <span style="opacity:0.65;">{{ __('Period') }}</span>
            <x-filament::input.wrapper style="max-width:14rem;">
                <x-filament::input.select wire:model.live="period">
                    @foreach ($this->periodOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    </x-filament::section>

    @unless ($hasData)
        <x-filament::section>
            <p style="opacity:0.65;">
                {{ __("No DNS activity recorded in this window. Domains appear once a user with logging enabled connects and their device uses this service's resolver.") }}
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ __('By category') }}</x-slot>

            <div style="display:flex;flex-direction:column;gap:0.75rem;">
                @foreach ($breakdown as $row)
                    <div>
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;">
                            <span style="display:inline-flex;align-items:center;gap:0.5rem;">
                                <x-filament::badge :color="$row['category']->getColor()">
                                    {{ $row['category']->getLabel() }}
                                </x-filament::badge>
                                <span style="opacity:0.65;">{{ __(':count domains', ['count' => $row['domains']]) }}</span>
                            </span>
                            <span style="font-weight:600;">{{ number_format($row['hits']) }} <span style="opacity:0.65;font-weight:400;">({{ $row['percent'] }}%)</span></span>
                        </div>
                        <div style="margin-top:0.25rem;height:0.5rem;width:100%;overflow:hidden;border-radius:9999px;background:rgba(128,128,128,0.2);">
                            <div style="height:100%;border-radius:9999px;background:rgb(var(--primary-500, 59 130 246));width:{{ max(1, $row['percent']) }}%;"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;">
            <div style="flex:2 1 22rem;min-width:0;">
                <x-filament::section>
                    <x-slot name="heading">{{ __('Most visited domains') }}</x-slot>

                    <div style="display:flex;flex-direction:column;">
                        @foreach ($topDomains as $d)
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;padding:0.5rem 0;border-top:1px solid rgba(128,128,128,0.15);">
                                <span style="display:inline-flex;align-items:center;gap:0.5rem;min-width:0;">
                                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $d->host }}</span>
                                    <x-filament::badge :color="$d->category->getColor()" size="sm">
                                        {{ $d->category->getLabel() }}
                                    </x-filament::badge>
                                </span>
                                <span style="flex-shrink:0;opacity:0.65;">{{ number_format($d->hits) }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            </div>

            <div style="flex:1 1 16rem;min-width:0;">
                <x-filament::section>
                    <x-slot name="heading">{{ __('Most active users') }}</x-slot>

                    @if ($topUsers === [])
                        <p style="opacity:0.65;">{{ __('No per-user data.') }}</p>
                    @else
                        <div style="display:flex;flex-direction:column;">
                            @foreach ($topUsers as $u)
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;padding:0.5rem 0;border-top:1px solid rgba(128,128,128,0.15);">
                                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $u['name'] }}</span>
                                    <span style="flex-shrink:0;opacity:0.65;">{{ number_format($u['hits']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>
            </div>
        </div>
    @endunless
</x-filament-panels::page>
