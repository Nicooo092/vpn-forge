<div class="flex flex-col gap-4">
    @if ($dataUri === null)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ $message }}
        </p>
    @else
        {{-- Two things this markup has to get right, both measured rather than
             guessed (screenshot the modal, decode it back with OpenCV):

             - The plate is explicitly white (bg-white, not a theme colour). The
               modules are drawn dark, so in dark mode the modal background would
               otherwise sit behind them and leave nothing for a camera to read.
             - The code is drawn as large as the modal allows. A 336-byte
               config is 65x65 modules and decodes from ~288px, but a heavier
               one (long endpoint FQDN, several DNS servers, a split-tunnel
               AllowedIPs list) reaches 77x77 and needs ~512px before it reads
               back reliably. Sizing for the dense case costs nothing
               (max-w-xl is 36rem). --}}
        <div class="flex justify-center">
            <div class="w-full max-w-xl rounded-lg bg-white p-4">
                <img src="{{ $dataUri }}" alt="{{ __('WireGuard configuration QR code') }}" class="block h-auto w-full">
            </div>
        </div>

        <p class="text-center text-sm text-gray-500 dark:text-gray-400">
            {!! __('In the WireGuard app, tap :plus &rarr; :scan, then name the tunnel :name.', [
                'plus' => '<strong>+</strong>',
                'scan' => '<strong>' . e(__('Scan from QR code')) . '</strong>',
                'name' => '<strong>' . e($tunnelName) . '</strong>',
            ]) !!}
        </p>

        <p class="text-center text-xs text-gray-500 dark:text-gray-400">
            {{ __("This code carries the client's private key -- treat it like the config file itself.") }}
        </p>
    @endif
</div>
