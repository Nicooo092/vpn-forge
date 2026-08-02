<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('Getting started') }}</x-slot>
        <x-slot name="description">{{ __("A few steps to a working tunnel. This disappears once you're set up.") }}</x-slot>

        {{-- Each step is a native compact section (icon + heading + description,
             self-styled). The button rides in the header via afterHeader. The
             only inline style is the vertical gap between them: this panel ships
             no compiled Tailwind, so utility classes like space-y/gap resolve to
             nothing -- an inline style is the reliable way to space them. --}}
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            @foreach ($this->getSteps() as $i => $step)
                <x-filament::section
                    compact
                    :icon="$step['done'] ? 'heroicon-o-check-circle' : $step['icon']"
                    :icon-color="$step['done'] ? 'success' : 'primary'"
                >
                    <x-slot name="heading">{{ ($i + 1) . '. ' . __($step['title']) }}</x-slot>
                    <x-slot name="description">{{ __($step['description']) }}</x-slot>

                    @unless ($step['done'])
                        <x-slot name="afterHeader">
                            <x-filament::button tag="a" :href="$step['url']" size="sm" color="gray">
                                {{ __($step['cta']) }}
                            </x-filament::button>
                        </x-slot>
                    @endunless
                </x-filament::section>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
