<x-filament-panels::page>
    <div class="flex items-center gap-3">
        <span class="text-sm text-gray-500 dark:text-gray-400">Period</span>
        <select
            wire:model.live="period"
            class="fi-input rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"
        >
            @foreach ($this->periodOptions() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @unless ($hasData)
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No DNS activity recorded in this window. Domains appear once a user with logging enabled connects
                and their device uses this service's resolver.
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">By category</x-slot>

            <div class="space-y-3">
                @foreach ($breakdown as $row)
                    <div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="flex items-center gap-2">
                                <x-filament::badge :color="$row['category']->getColor()">
                                    {{ $row['category']->getLabel() }}
                                </x-filament::badge>
                                <span class="text-gray-500 dark:text-gray-400">{{ $row['domains'] }} domains</span>
                            </span>
                            <span class="font-medium">{{ number_format($row['hits']) }} <span class="text-gray-500 dark:text-gray-400">({{ $row['percent'] }}%)</span></span>
                        </div>
                        <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                            <div class="h-full rounded-full bg-primary-500" style="width: {{ max(1, $row['percent']) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <div class="grid gap-6 lg:grid-cols-3">
            <x-filament::section class="lg:col-span-2">
                <x-slot name="heading">Most visited domains</x-slot>

                <div class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                    @foreach ($topDomains as $d)
                        <div class="flex items-center justify-between gap-3 py-2">
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="truncate">{{ $d->host }}</span>
                                <x-filament::badge :color="$d->category->getColor()" size="sm">
                                    {{ $d->category->getLabel() }}
                                </x-filament::badge>
                            </span>
                            <span class="shrink-0 text-gray-500 dark:text-gray-400">{{ number_format($d->hits) }}</span>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Most active users</x-slot>

                @if ($topUsers === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">No per-user data.</p>
                @else
                    <div class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                        @foreach ($topUsers as $u)
                            <div class="flex items-center justify-between gap-3 py-2">
                                <span class="truncate">{{ $u['name'] }}</span>
                                <span class="shrink-0 text-gray-500 dark:text-gray-400">{{ number_format($u['hits']) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        </div>
    @endunless
</x-filament-panels::page>
