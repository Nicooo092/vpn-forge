<x-filament-panels::page>
    @unless ($this->isSecure())
        {{-- Stated where the download button is, not buried in a doc: this
             archive holds every private key on the server and the database
             password, and this panel is currently served over plain HTTP. --}}
        <x-filament::section>
            <x-slot name="heading">This connection is not encrypted</x-slot>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                A backup contains every private key on this server, the OpenVPN certificate authority and the
                database password. Downloading it over plain HTTP sends all of that across the network in the
                clear. Set up HTTPS before downloading one, or copy the file off the server directly with
                <code>scp</code> from <code>{{ \App\Services\Backup\BackupArchive::DIRECTORY }}</code>.
            </p>
        </x-filament::section>
    @endunless

    <x-filament::section>
        <x-slot name="heading">Archives</x-slot>

        @php($backups = $this->getBackups())

        @if ($backups === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No backups yet. Use "Create backup" above -- it takes a few seconds and runs on the privileged
                worker, which is the only process able to read the certificate authority.
            </p>
        @else
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($backups as $backup)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <p class="font-mono text-sm">{{ $backup['name'] }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ number_format($backup['size'] / 1024 / 1024, 1) }} MB &middot;
                                {{ \Illuminate\Support\Carbon::createFromTimestamp($backup['created_at'])->diffForHumans() }}
                            </p>
                        </div>

                        <div class="flex gap-2">
                            <x-filament::button
                                size="sm"
                                icon="heroicon-o-arrow-down-tray"
                                wire:click="download('{{ $backup['name'] }}')"
                            >
                                Download
                            </x-filament::button>

                            <x-filament::button
                                size="sm"
                                color="warning"
                                icon="heroicon-o-arrow-uturn-left"
                                wire:click="restore('{{ $backup['name'] }}')"
                                wire:confirm="Restore this backup? This OVERWRITES the current database and every key on the server with the ones in this archive. It cannot be undone. Reboot the server afterwards to bring the tunnels back up."
                            >
                                Restore
                            </x-filament::button>

                            <x-filament::button
                                size="sm"
                                color="danger"
                                icon="heroicon-o-trash"
                                wire:click="delete('{{ $backup['name'] }}')"
                                wire:confirm="Delete this backup?"
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
