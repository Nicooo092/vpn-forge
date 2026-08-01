<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Scheduled reports</x-slot>

        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ $this->getScheduleSummary() }}
            Reports and log exports are kept for {{ config('vpnforge.exports.retention_days', 60) }} days, then removed.
        </p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Files</x-slot>

        @php($exports = $this->getExports())

        @if ($exports === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nothing here yet. Use "Generate reports now" or "Export logs (CSV)" above, or wait for the next
                scheduled run.
            </p>
        @else
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($exports as $export)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <p class="font-mono text-sm">{{ $export['name'] }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                @if ($export['size'] >= 1024 * 1024)
                                    {{ number_format($export['size'] / 1024 / 1024, 1) }} MB
                                @else
                                    {{ number_format($export['size'] / 1024, 1) }} KB
                                @endif
                                &middot;
                                {{ \Illuminate\Support\Carbon::createFromTimestamp($export['created_at'])->diffForHumans() }}
                            </p>
                        </div>

                        <div class="flex gap-2">
                            <x-filament::button
                                size="sm"
                                icon="heroicon-o-arrow-down-tray"
                                wire:click="download('{{ $export['name'] }}')"
                            >
                                Download
                            </x-filament::button>

                            <x-filament::button
                                size="sm"
                                color="danger"
                                icon="heroicon-o-trash"
                                wire:click="delete('{{ $export['name'] }}')"
                                wire:confirm="Delete this export?"
                            >
                                Delete
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
