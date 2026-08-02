<x-filament-panels::page>
    @php($s = $this->getSnapshot())

    {{-- Load / memory / disk --}}
    <div class="grid gap-6 md:grid-cols-3">
        <x-filament::section>
            <x-slot name="heading">CPU load</x-slot>

            @php($cores = $s['cpu']['cores'])
            @php($load1 = $s['cpu']['load1'])
            <div class="text-3xl font-bold">
                {{ $load1 !== null ? number_format($load1, 2) : '--' }}
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                1&nbsp;min load{{ $cores ? ' · '.$cores.' core'.($cores === 1 ? '' : 's') : '' }}
            </p>
            @if ($load1 !== null && $cores)
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div @class([
                        'h-full rounded-full',
                        'bg-primary-500' => $load1 / $cores < 0.8,
                        'bg-danger-500' => $load1 / $cores >= 0.8,
                    ]) style="width: {{ min(100, round($load1 / $cores * 100)) }}%"></div>
                </div>
            @endif
            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                5&nbsp;min {{ $s['cpu']['load5'] !== null ? number_format($s['cpu']['load5'], 2) : '--' }}
                · 15&nbsp;min {{ $s['cpu']['load15'] !== null ? number_format($s['cpu']['load15'], 2) : '--' }}
            </p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Memory</x-slot>

            @php($mem = $s['memory'])
            <div class="text-3xl font-bold">{{ $mem['percent'] !== null ? $mem['percent'].'%' : '--' }}</div>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $this->formatBytes($mem['used']) }} of {{ $this->formatBytes($mem['total']) }} used
            </p>
            @if ($mem['percent'] !== null)
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div @class([
                        'h-full rounded-full',
                        'bg-primary-500' => $mem['percent'] < 90,
                        'bg-danger-500' => $mem['percent'] >= 90,
                    ]) style="width: {{ $mem['percent'] }}%"></div>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Disk</x-slot>

            @php($disk = $s['disk'])
            <div class="text-3xl font-bold">{{ $disk['percent'] !== null ? $disk['percent'].'%' : '--' }}</div>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $this->formatBytes($disk['used']) }} of {{ $this->formatBytes($disk['total']) }} used
                · {{ $this->formatBytes($disk['free']) }} free
            </p>
            @if ($disk['percent'] !== null)
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div @class([
                        'h-full rounded-full',
                        'bg-primary-500' => $disk['percent'] < 85,
                        'bg-danger-500' => $disk['percent'] >= 85,
                    ]) style="width: {{ $disk['percent'] }}%"></div>
                </div>
                @if ($disk['percent'] >= 85)
                    <p class="mt-2 text-xs text-danger-600 dark:text-danger-400">
                        Running low. The DNS traffic log is usually what grows -- shorten retention or turn logging off for some users.
                    </p>
                @endif
            @endif
        </x-filament::section>
    </div>

    {{-- Panel counts + uptime + traffic --}}
    <x-filament::section>
        <x-slot name="heading">At a glance</x-slot>

        @php($c = $s['counts'])
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 text-sm">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Services</dt>
                <dd class="text-lg font-semibold">{{ $c['services_active'] }} active <span class="text-gray-400">/ {{ $c['services_total'] }}</span></dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Users</dt>
                <dd class="text-lg font-semibold">{{ $c['users_active'] }} active <span class="text-gray-400">/ {{ $c['users_total'] }}</span></dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Connected now</dt>
                <dd class="text-lg font-semibold">{{ $c['connected_now'] }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Traffic (24h)</dt>
                <dd class="text-lg font-semibold">{{ $this->formatBytes($s['bandwidth_24h']) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Uptime</dt>
                <dd class="text-lg font-semibold">{{ $this->formatDuration($s['uptime_seconds']) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">PHP</dt>
                <dd class="text-lg font-semibold">{{ PHP_VERSION }}</dd>
            </div>
        </dl>
    </x-filament::section>
</x-filament-panels::page>
