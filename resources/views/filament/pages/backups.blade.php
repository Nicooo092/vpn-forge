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
            {{-- No compiled Tailwind ships with this panel, so utility classes
                 resolve to nothing. Each archive is its own native compact
                 section (self-styled), spaced by an inline-flex column. --}}
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                @foreach ($backups as $backup)
                    <x-filament::section compact icon="heroicon-o-archive-box" icon-color="gray">
                        <x-slot name="heading">{{ $backup['name'] }}</x-slot>
                        <x-slot name="description">{{ number_format($backup['size'] / 1024 / 1024, 1) }} MB &middot; {{ \Illuminate\Support\Carbon::createFromTimestamp($backup['created_at'])->diffForHumans() }}</x-slot>

                        <x-slot name="afterHeader">
                            <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                                <x-filament::button
                                    size="sm"
                                    icon="heroicon-o-arrow-down-tray"
                                    wire:click="download('{{ $backup['name'] }}')"
                                >
                                    {{ __('Download') }}
                                </x-filament::button>

                                <x-filament::button
                                    size="sm"
                                    color="warning"
                                    icon="heroicon-o-arrow-uturn-left"
                                    wire:click="restore('{{ $backup['name'] }}')"
                                    wire:confirm="{{ __('Restore this backup? This OVERWRITES the current database and every key on the server with the ones in this archive. It cannot be undone. Reboot the server afterwards to bring the tunnels back up.') }}"
                                >
                                    {{ __('Restore') }}
                                </x-filament::button>

                                <x-filament::button
                                    size="sm"
                                    color="danger"
                                    icon="heroicon-o-trash"
                                    wire:click="delete('{{ $backup['name'] }}')"
                                    wire:confirm="{{ __('Delete this backup?') }}"
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
