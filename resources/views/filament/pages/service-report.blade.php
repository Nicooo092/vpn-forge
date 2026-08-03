<x-filament-panels::page>
    <x-filament::section compact>
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Period') }}</span>
            <x-filament::input.wrapper class="max-w-56">
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
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __("No DNS activity recorded in this window. Domains appear once a user with logging enabled connects and their device uses this service's resolver.") }}
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ __('By category') }}</x-slot>

            <div class="flex flex-col gap-3">
                @foreach ($breakdown as $row)
                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="inline-flex items-center gap-2">
                                <x-filament::badge :color="$row['category']->getColor()">
                                    {{ $row['category']->getLabel() }}
                                </x-filament::badge>
                                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __(':count domains', ['count' => $row['domains']]) }}</span>
                            </span>
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ number_format($row['hits']) }}
                                <span class="font-normal text-gray-500 dark:text-gray-400">({{ $row['percent'] }}%)</span>
                            </span>
                        </div>
                        <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                            {{-- vf-gauge-fill sweeps the bar out from its left
                                 edge on arrival, so the chart reads as a
                                 measurement being taken rather than a static
                                 block. The width stays the source of truth; the
                                 animation only scales it. --}}
                            <div class="vf-gauge-fill h-full rounded-full bg-primary-500" style="width: {{ max(1, $row['percent']) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-filament::section>
                    <x-slot name="heading">{{ __('Most visited domains') }}</x-slot>

                    <div class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($topDomains as $d)
                            <div class="flex items-center justify-between gap-3 py-2">
                                <span class="inline-flex min-w-0 items-center gap-2">
                                    <span class="truncate">{{ $d->host }}</span>
                                    <x-filament::badge :color="$d->category->getColor()" size="sm">
                                        {{ $d->category->getLabel() }}
                                    </x-filament::badge>
                                </span>
                                <span class="shrink-0 text-sm text-gray-500 dark:text-gray-400">{{ number_format($d->hits) }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            </div>

            <div>
                <x-filament::section>
                    <x-slot name="heading">{{ __('Most active users') }}</x-slot>

                    @if ($topUsers === [])
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No per-user data.') }}</p>
                    @else
                        <div class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($topUsers as $u)
                                <div class="flex items-center justify-between gap-3 py-2">
                                    <span class="truncate">{{ $u['name'] }}</span>
                                    <span class="shrink-0 text-sm text-gray-500 dark:text-gray-400">{{ number_format($u['hits']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>
            </div>
        </div>
    @endunless
</x-filament-panels::page>
