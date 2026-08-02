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
    @php($currentMono = '<span style="font-family:ui-monospace,monospace;">' . e($status['current'] ?? __('unknown')) . '</span>')

    <x-filament::section :icon="$statusIcon" :icon-color="$statusColor">
        <x-slot name="heading">{{ __('Version') }}</x-slot>

        <div style="display:flex;flex-direction:column;gap:1rem;">
            @if ($status['status'] === 'up-to-date')
                <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                    <x-filament::badge color="success">{{ __('Up to date') }}</x-filament::badge>
                    <span>{!! __('Running :version.', ['version' => $currentMono]) !!}</span>
                </div>
            @elseif ($status['status'] === 'behind')
                <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                    <x-filament::badge color="warning">{{ __('Update available') }}</x-filament::badge>
                    <span>{{ __(':count commits behind', ['count' => $status['behind']]) }} &middot; {!! __('running :version.', ['version' => $currentMono]) !!}</span>
                </div>

                @if ($status['commits'] !== [])
                    <div>
                        <p style="font-weight:600;margin-bottom:0.25rem;">{{ __("What's new") }}</p>
                        <ul style="list-style:disc;padding-inline-start:1.25rem;display:flex;flex-direction:column;gap:0.25rem;">
                            @foreach ($status['commits'] as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @else
                <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                    <x-filament::badge color="gray">{{ __('Unknown') }}</x-filament::badge>
                    <span>{{ $status['message'] ?? __('Could not check for updates.') }}</span>
                </div>
            @endif

            @if ($status['checked_at'])
                <p style="opacity:0.65;">
                    {{ __('Last checked :time. Use "Check now" above to refresh.', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($status['checked_at'])->diffForHumans()]) }}
                </p>
            @endif
        </div>
    </x-filament::section>

    @unless ($status['status'] === 'up-to-date')
        <x-filament::section>
            <x-slot name="heading">{{ __('How to upgrade') }}</x-slot>

            <div style="display:flex;flex-direction:column;gap:0.75rem;">
                <p style="opacity:0.65;">
                    {{ __("The panel never updates itself -- that is a deliberate boundary, so the web process can't redeploy the server. Connect over SSH and run this:") }}
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
                    style="position:relative;"
                >
                    <pre style="overflow-x:auto;border-radius:0.5rem;background:#0a0a0a;color:#f3f4f6;padding:1rem;font-size:0.8rem;line-height:1.6;"><code x-text="command"></code></pre>

                    <x-filament::button
                        size="xs"
                        color="gray"
                        icon="heroicon-o-clipboard"
                        x-on:click="copy()"
                        style="position:absolute;right:0.5rem;top:0.5rem;"
                    >
                        <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></span>
                    </x-filament::button>
                </div>

                <p style="opacity:0.65;">
                    {{ __('It fast-forwards the code, installs dependencies, runs migrations and restarts the worker. Take a backup first (Backups page) if you want a fallback.') }}
                </p>
            </div>
        </x-filament::section>
    @endunless
</x-filament-panels::page>
