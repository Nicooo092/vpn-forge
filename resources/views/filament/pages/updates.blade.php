<x-filament-panels::page>
    @php($status = $this->getStatus())
    @php($statusIcon = match ($status['status']) {
        'up-to-date' => 'heroicon-o-check-circle',
        'behind' => 'heroicon-o-arrow-up-circle',
        default => 'heroicon-o-question-mark-circle',
    })
    @php($statusColor = match ($status['status']) {
        'up-to-date' => 'success',
        'behind' => 'warning',
        default => 'gray',
    })
    {{-- Rendered as a plain <code> element inside the translated sentence: the
         markup carries no styling of its own, so it follows the theme in both
         light and dark instead of a hardcoded font stack. --}}
    @php($currentVersion = '<code>' . e($status['current'] ?? __('unknown')) . '</code>')

    <x-filament::section :icon="$statusIcon" :icon-color="$statusColor">
        <x-slot name="heading">{{ __('Version') }}</x-slot>

        <div class="flex flex-col gap-4">
            @if ($status['status'] === 'up-to-date')
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge color="success">{{ __('Up to date') }}</x-filament::badge>
                    <span>{!! __('Running :version.', ['version' => $currentVersion]) !!}</span>
                </div>
            @elseif ($status['status'] === 'behind')
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge color="warning">{{ __('Update available') }}</x-filament::badge>
                    <span>{{ __(':count commits behind', ['count' => $status['behind']]) }} &middot; {!! __('running :version.', ['version' => $currentVersion]) !!}</span>
                </div>

                @if ($status['commits'] !== [])
                    <div>
                        <p class="mb-1 font-semibold">{{ __("What's new") }}</p>
                        <ul class="list-disc space-y-1 ps-5">
                            @foreach ($status['commits'] as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @else
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge color="gray">{{ __('Unknown') }}</x-filament::badge>
                    <span>{{ $status['message'] ?? __('Could not check for updates.') }}</span>
                </div>
            @endif

            @if ($status['checked_at'])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Last checked :time. Use "Check now" above to refresh.', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($status['checked_at'])->diffForHumans()]) }}
                </p>
            @endif
        </div>
    </x-filament::section>

    @unless ($status['status'] === 'up-to-date')
        <x-filament::section>
            <x-slot name="heading">{{ __('How to upgrade') }}</x-slot>

            <div class="flex flex-col gap-3">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __("The panel never updates itself -- that is a deliberate boundary, so the web process can't redeploy the server. Connect over SSH and run this:") }}
                </p>

                {{-- navigator.clipboard exists only in secure contexts and this
                     panel is routinely served over plain HTTP (see the warning on
                     the Backups page), so the copy button must carry the same
                     textarea + execCommand fallback as the config-link reveal
                     page -- and 'Copied' may only appear when a copy actually
                     succeeded, never on a rejected or refused attempt. --}}
                <div
                    x-data="{
                        command: @js($this->getUpgradeCommand()),
                        copied: false,
                        copy() {
                            const markCopied = () => {
                                this.copied = true;
                                setTimeout(() => this.copied = false, 1500);
                            };
                            const fallback = () => {
                                const scratch = document.createElement('textarea');
                                scratch.value = this.command;
                                scratch.setAttribute('readonly', '');
                                scratch.style.position = 'absolute';
                                scratch.style.left = '-9999px';
                                document.body.appendChild(scratch);
                                scratch.select();
                                const ok = document.execCommand('copy');
                                document.body.removeChild(scratch);
                                if (ok) markCopied();
                            };
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(this.command).then(markCopied, fallback);
                            } else {
                                fallback();
                            }
                        },
                    }"
                    class="relative"
                >
                    <pre class="overflow-x-auto rounded-lg bg-gray-50 p-4 text-sm text-gray-950 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-white dark:ring-white/10"><code x-text="command"></code></pre>

                    <x-filament::button
                        size="xs"
                        color="gray"
                        icon="heroicon-o-clipboard"
                        x-on:click="copy()"
                        class="absolute end-2 top-2"
                    >
                        <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></span>
                    </x-filament::button>
                </div>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('It fast-forwards the code, installs dependencies, runs migrations and restarts the worker. Take a backup first (Backups page) if you want a fallback.') }}
                </p>
            </div>
        </x-filament::section>
    @endunless
</x-filament-panels::page>
