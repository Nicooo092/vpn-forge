<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Getting started</x-slot>
        <x-slot name="description">A few steps to a working tunnel. This disappears once you're set up.</x-slot>

        @php($steps = $this->getSteps())

        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($steps as $i => $step)
                <div class="flex items-center gap-4 py-3">
                    <div class="shrink-0">
                        @if ($step['done'])
                            @svg('heroicon-s-check-circle', 'h-7 w-7 text-success-500')
                        @else
                            <span class="flex h-7 w-7 items-center justify-center rounded-full border border-gray-300 text-sm font-semibold text-gray-500 dark:border-white/20 dark:text-gray-400">
                                {{ $i + 1 }}
                            </span>
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <p @class([
                            'text-sm font-medium',
                            'text-gray-400 line-through dark:text-gray-500' => $step['done'],
                        ])>{{ $step['title'] }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $step['description'] }}</p>
                    </div>

                    @unless ($step['done'])
                        <div class="shrink-0">
                            <x-filament::button tag="a" :href="$step['url']" size="sm" color="gray">
                                {{ $step['cta'] }}
                            </x-filament::button>
                        </div>
                    @endunless
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
