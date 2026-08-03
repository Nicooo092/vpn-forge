<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ __('Scheduled reports') }}</x-slot>

        <p>
            {{ $this->getScheduleSummary() }}
            {{ __('Reports and log exports are kept for :days days, then removed.', ['days' => config('vpnforge.exports.retention_days', 60)]) }}
        </p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('Files') }}</x-slot>

        @php($exports = $this->getExports())

        @if ($exports === [])
            <p>{{ __('Nothing here yet. Use "Generate reports now" or "Export logs (CSV)" above, or wait for the next scheduled run.') }}</p>
        @else
            {{-- One row per file rather than a card per file, with the same column
                 widths and the same size formatting as the Backups page so the two
                 listings read identically. --}}
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($exports as $export)
                    @php($size = $export['size'] >= 1024 * 1024
                        ? number_format($export['size'] / 1024 / 1024, 1) . ' MB'
                        : number_format($export['size'] / 1024, 1) . ' KB')

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-3 py-3 first:pt-0 last:pb-0">
                        <x-filament::icon icon="heroicon-o-document" class="shrink-0 text-gray-400" />

                        <span class="min-w-0 flex-1 truncate font-medium text-gray-950 dark:text-white">
                            {{ $export['name'] }}
                        </span>

                        <span class="w-20 shrink-0 whitespace-nowrap text-end text-sm tabular-nums text-gray-500 dark:text-gray-400">
                            {{ $size }}
                        </span>

                        <span class="w-32 shrink-0 whitespace-nowrap text-end text-sm text-gray-500 dark:text-gray-400">
                            {{ \Illuminate\Support\Carbon::createFromTimestamp($export['created_at'])->diffForHumans() }}
                        </span>

                        <div class="flex shrink-0 items-center gap-2">
                            <x-filament::button
                                size="sm"
                                icon="heroicon-o-arrow-down-tray"
                                wire:click="download('{{ $export['name'] }}')"
                            >
                                {{ __('Download') }}
                            </x-filament::button>

                            <x-filament::button
                                size="sm"
                                color="danger"
                                icon="heroicon-o-trash"
                                wire:click="delete('{{ $export['name'] }}')"
                                wire:confirm="{{ __('Delete this export?') }}"
                            >
                                {{ __('Delete') }}
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
