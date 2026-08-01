<x-filament-panels::page>
    @php($status = $this->getStatus())

    <x-filament::section>
        <x-slot name="heading">Version</x-slot>

        <div class="space-y-4">
            @if ($status['status'] === 'up-to-date')
                <div class="flex items-center gap-2 text-sm">
                    <x-filament::badge color="success">Up to date</x-filament::badge>
                    <span class="text-gray-500 dark:text-gray-400">Running <span class="font-mono">{{ $status['current'] ?? 'unknown' }}</span>.</span>
                </div>
            @elseif ($status['status'] === 'behind')
                <div class="flex items-center gap-2 text-sm">
                    <x-filament::badge color="warning">Update available</x-filament::badge>
                    <span class="text-gray-500 dark:text-gray-400">
                        {{ $status['behind'] }} {{ \Illuminate\Support\Str::plural('commit', $status['behind']) }} behind
                        &middot; running <span class="font-mono">{{ $status['current'] ?? 'unknown' }}</span>.
                    </span>
                </div>

                @if ($status['commits'] !== [])
                    <div>
                        <p class="mb-1 text-sm font-medium">What's new</p>
                        <ul class="list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($status['commits'] as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @else
                <div class="flex items-center gap-2 text-sm">
                    <x-filament::badge color="gray">Unknown</x-filament::badge>
                    <span class="text-gray-500 dark:text-gray-400">{{ $status['message'] ?? 'Could not check for updates.' }}</span>
                </div>
            @endif

            @if ($status['checked_at'])
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    Last checked {{ \Illuminate\Support\Carbon::createFromTimestamp($status['checked_at'])->diffForHumans() }}.
                    Use "Check now" above to refresh.
                </p>
            @endif
        </div>
    </x-filament::section>

    @unless ($status['status'] === 'up-to-date')
        <x-filament::section>
            <x-slot name="heading">How to upgrade</x-slot>

            <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">
                The panel never updates itself -- that is a deliberate boundary, so the web process can't redeploy
                the server. Connect over SSH and run this:
            </p>

            <div
                x-data="{
                    command: @js($this->getUpgradeCommand()),
                    copied: false,
                    copy() {
                        navigator.clipboard.writeText(this.command).then(() => {
                            this.copied = true;
                            setTimeout(() => this.copied = false, 1500);
                        });
                    },
                }"
                class="relative"
            >
                <pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs leading-relaxed text-gray-100 dark:bg-black"><code x-text="command"></code></pre>

                <x-filament::button
                    size="xs"
                    color="gray"
                    icon="heroicon-o-clipboard"
                    class="absolute right-2 top-2"
                    x-on:click="copy()"
                >
                    <span x-text="copied ? 'Copied' : 'Copy'"></span>
                </x-filament::button>
            </div>

            <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                It fast-forwards the code, installs dependencies, runs migrations and restarts the worker. Take a
                backup first (Backups page) if you want a fallback.
            </p>
        </x-filament::section>
    @endunless
</x-filament-panels::page>
