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
            {{-- No compiled Tailwind ships with this panel, so utility classes
                 resolve to nothing. Each file is its own native compact section
                 (self-styled), spaced by an inline-flex column. --}}
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                @foreach ($exports as $export)
                    @php($size = $export['size'] >= 1024 * 1024
                        ? number_format($export['size'] / 1024 / 1024, 1) . ' MB'
                        : number_format($export['size'] / 1024, 1) . ' KB')

                    <x-filament::section compact icon="heroicon-o-document" icon-color="gray">
                        <x-slot name="heading">{{ $export['name'] }}</x-slot>
                        <x-slot name="description">{{ $size }} &middot; {{ \Illuminate\Support\Carbon::createFromTimestamp($export['created_at'])->diffForHumans() }}</x-slot>

                        <x-slot name="afterHeader">
                            <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
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
                        </x-slot>
                    </x-filament::section>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
