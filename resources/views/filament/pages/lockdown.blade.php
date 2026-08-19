<x-filament-panels::page>
    @php($state = $this->lockdownState())

    @if ($this->isEngaged())
        <x-filament::section icon="heroicon-o-lock-closed" icon-color="danger">
            <x-slot name="heading">{{ __('Lockdown is active') }}</x-slot>
            <x-slot name="description">{{ __('Every user on every service is suspended. All VPN access is blocked until you lift it.') }}</x-slot>

            @if ($state)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Engaged :time by :who', [
                        'time' => \Illuminate\Support\Carbon::parse($state['engaged_at'])->diffForHumans(),
                        'who' => $state['engaged_by_email'] ?? __('an operator'),
                    ]) }}
                </p>
            @endif

            <p class="mt-2">
                {{ __('Use "Lift lockdown" above to restore the users lockdown suspended. You will be asked for your authenticator code again to confirm.') }}
            </p>
        </x-filament::section>
    @else
        <x-filament::section icon="heroicon-o-lock-open" icon-color="success">
            <x-slot name="heading">{{ __('Lockdown is not active') }}</x-slot>
            <x-slot name="description">{{ __('VPN access is running normally.') }}</x-slot>

            <p>
                {{ __('Use "Engage lockdown" above to immediately suspend every user on every service and block all VPN access at once. It is confirmed with your authenticator code, and takes the same code again to lift. Only the users lockdown suspends are restored when you lift it -- a user already suspended, expired or out of allowance is left as it was.') }}
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
