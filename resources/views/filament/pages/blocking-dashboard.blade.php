<x-filament-panels::page>
    @php
        $stats = $this->stats();
        $top = $this->topBlocked();
    @endphp

    {{-- Read-only filters: window and service. Both are plain Livewire
         properties (wire:model.live), no mutation, so an auditor drives them
         exactly as an admin does. --}}
    <div class="flex flex-wrap items-end gap-4">
        <label class="flex flex-col gap-1 text-sm">
            <span class="font-medium text-gray-950 dark:text-white">{{ __('Window') }}</span>
            <select
                wire:model.live="window"
                class="rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
            >
                @foreach ($this->windowOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex flex-col gap-1 text-sm">
            <span class="font-medium text-gray-950 dark:text-white">{{ __('Service') }}</span>
            <select
                wire:model.live="serviceId"
                class="rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
            >
                @foreach ($this->serviceOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('Total lookups') }}</div>
            <div class="mt-1 text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">
                {{ number_format($stats['total']) }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('Blocked') }}</div>
            <div class="mt-1 text-3xl font-semibold tabular-nums text-danger-600 dark:text-danger-400">
                {{ number_format($stats['blocked']) }}
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Answered locally, never forwarded') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('Allowed') }}</div>
            <div class="mt-1 text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">
                {{ number_format($stats['allowed']) }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('Block rate') }}</div>
            <div class="mt-1 text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">
                {{ $stats['rate'] }}%
            </div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">{{ __('Top blocked domains') }}</x-slot>

        @if ($top->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No blocked lookups in this window') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">{{ __('Domain') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Blocked lookups') }}</th>
                            <th class="py-2 font-medium">{{ __('Last blocked') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($top as $row)
                            <tr>
                                <td class="py-2 pr-4 font-mono text-gray-950 dark:text-white">{{ $row->host }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ number_format($row->hits) }}</td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">
                                    {{ \Illuminate\Support\Carbon::parse($row->last_seen)->diffForHumans() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    @if ($this->serviceId === null)
        @php $perService = $this->perService(); @endphp
        <x-filament::section>
            <x-slot name="heading">{{ __('Blocked by service') }}</x-slot>

            @if ($perService->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No DNS activity in this window') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-4 font-medium">{{ __('Service') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Lookups') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Blocked') }}</th>
                                <th class="py-2 font-medium">{{ __('Block rate') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($perService as $row)
                                <tr>
                                    <td class="py-2 pr-4 text-gray-950 dark:text-white">{{ $row['name'] }}</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ number_format($row['total']) }}</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ number_format($row['blocked']) }}</td>
                                    <td class="py-2 tabular-nums">{{ $row['rate'] }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
