<x-filament-panels::page>
    @unless ($this->isSecure())
        {{-- Stated where the download button is, not buried in a doc: this
             archive holds every private key on the server and the database
             password, and this panel is currently served over plain HTTP. --}}
        <x-filament::section icon="heroicon-o-lock-open" icon-color="danger">
            <x-slot name="heading">{{ __('This connection is not encrypted') }}</x-slot>

            <p>
                {!! __('A backup contains every private key on this server, the OpenVPN certificate authority and the database password. Downloading it over plain HTTP sends all of that across the network in the clear. Set up HTTPS before downloading one, or copy the file off the server directly with :scp from :dir.', [
                    'scp' => '<code>scp</code>',
                    'dir' => '<code>' . e(\App\Services\Backup\BackupArchive::DIRECTORY) . '</code>',
                ]) !!}
            </p>
        </x-filament::section>
    @endunless

    <x-filament::section>
        <x-slot name="heading">{{ __('Archives') }}</x-slot>

        @php($backups = $this->getBackups())

        @if ($backups === [])
            <p>{{ __('No backups yet. Use "Create backup" above -- it takes a few seconds and runs on the privileged worker, which is the only process able to read the certificate authority.') }}</p>
        @else
            {{-- One row per archive rather than a card per archive: name, size and
                 age line up in fixed columns so the list can be read down, and the
                 actions always end at the same right edge. --}}
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($backups as $backup)
                    @php($size = $backup['size'] >= 1024 * 1024
                        ? number_format($backup['size'] / 1024 / 1024, 1) . ' MB'
                        : number_format($backup['size'] / 1024, 1) . ' KB')

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-3 py-3 first:pt-0 last:pb-0">
                        <x-filament::icon icon="heroicon-o-archive-box" class="shrink-0 text-gray-400" />

                        <span class="min-w-0 flex-1 truncate font-medium text-gray-950 dark:text-white">
                            {{ $backup['name'] }}
                        </span>

                        <span class="w-20 shrink-0 whitespace-nowrap text-end text-sm tabular-nums text-gray-500 dark:text-gray-400">
                            {{ $size }}
                        </span>

                        <span class="w-32 shrink-0 whitespace-nowrap text-end text-sm text-gray-500 dark:text-gray-400">
                            {{ \Illuminate\Support\Carbon::createFromTimestamp($backup['created_at'])->diffForHumans() }}
                        </span>

                        <div class="flex shrink-0 items-center gap-2">
                            <x-filament::button
                                size="sm"
                                icon="heroicon-o-arrow-down-tray"
                                wire:click="download('{{ $backup['name'] }}')"
                            >
                                {{ __('Download') }}
                            </x-filament::button>

                            {{-- Restore is the destructive action here, not Delete:
                                 it overwrites the live database and every key on the
                                 server and cannot be undone, while Delete only removes
                                 this one archive. The colours say so. --}}
                            <x-filament::button
                                size="sm"
                                color="danger"
                                icon="heroicon-o-arrow-uturn-left"
                                wire:click="restore('{{ $backup['name'] }}')"
                                wire:confirm="{{ __('Restore this backup? This OVERWRITES the current database and every key on the server with the ones in this archive. It cannot be undone. Reboot the server afterwards to bring the tunnels back up.') }}"
                            >
                                {{ __('Restore') }}
                            </x-filament::button>

                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="heroicon-o-trash"
                                wire:click="delete('{{ $backup['name'] }}')"
                                wire:confirm="{{ __('Delete this backup?') }}"
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
