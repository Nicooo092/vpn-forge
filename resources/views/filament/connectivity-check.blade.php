<div style="display:flex;flex-direction:column;gap:0.75rem;">
    @foreach ($checks as $check)
        <div style="display:flex;gap:0.75rem;align-items:flex-start;">
            <div style="flex-shrink:0;margin-top:0.125rem;">
                @if ($check['ok'] === true)
                    <x-filament::icon icon="heroicon-o-check-circle" style="width:1.25rem;height:1.25rem;color:rgb(var(--success-500, 34 197 94));" />
                @elseif ($check['ok'] === false)
                    <x-filament::icon icon="heroicon-o-x-circle" style="width:1.25rem;height:1.25rem;color:rgb(var(--danger-500, 239 68 68));" />
                @else
                    <x-filament::icon icon="heroicon-o-question-mark-circle" style="width:1.25rem;height:1.25rem;color:rgb(var(--gray-400, 156 163 175));" />
                @endif
            </div>

            <div style="min-width:0;">
                <p style="font-weight:500;">{{ $check['label'] }}</p>
                <p style="opacity:0.65;">{{ $check['detail'] }}</p>
            </div>
        </div>
    @endforeach

    {{-- Stated plainly because a clean run is otherwise easy to read as
         "everything is fine" when the one thing that most often blocks a
         tunnel is invisible from in here. --}}
    <p style="border-top:1px solid rgba(128,128,128,0.2);padding-top:0.75rem;opacity:0.65;">
        {!! __("These checks run on the server itself. They cannot see your cloud provider's firewall or security group -- if every line above passes and clients still cannot connect, that is where to look next: allow :endpoint inbound.", [
            'endpoint' => '<strong>' . e($port . '/' . $transport) . '</strong>',
        ]) !!}
    </p>
</div>
