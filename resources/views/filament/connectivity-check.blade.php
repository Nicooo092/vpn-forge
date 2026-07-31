<div class="space-y-3">
    @foreach ($checks as $check)
        <div class="flex gap-3">
            <div class="mt-0.5 shrink-0">
                @if ($check['ok'] === true)
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-500" />
                @elseif ($check['ok'] === false)
                    <x-filament::icon icon="heroicon-o-x-circle" class="h-5 w-5 text-danger-500" />
                @else
                    <x-filament::icon icon="heroicon-o-question-mark-circle" class="h-5 w-5 text-gray-400" />
                @endif
            </div>

            <div>
                <p class="text-sm font-medium">{{ $check['label'] }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</p>
            </div>
        </div>
    @endforeach

    {{-- Stated plainly because a clean run is otherwise easy to read as
         "everything is fine" when the one thing that most often blocks a
         tunnel is invisible from in here. --}}
    <p class="border-t border-gray-200 pt-3 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
        These checks run on the server itself. They cannot see your cloud provider's firewall or security group --
        if every line above passes and clients still cannot connect, that is where to look next: allow
        <strong>{{ $port }}/{{ $transport }}</strong> inbound.
    </p>
</div>
