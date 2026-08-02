<div style="display:flex;flex-direction:column;gap:1rem;">
    @if ($dataUri === null)
        <p style="opacity:0.65;">
            {{ $message }}
        </p>
    @else
        {{-- Two things this markup has to get right, both measured rather than
             guessed (screenshot the modal, decode it back with OpenCV):

             - The plate is explicitly white. The modules are drawn dark, so in
               dark mode the modal background would otherwise sit behind them
               and leave nothing for a camera to read.
             - The code is drawn as large as the modal allows. A 336-byte
               config is 65x65 modules and decodes from ~288px, but a heavier
               one (long endpoint FQDN, several DNS servers, a split-tunnel
               AllowedIPs list) reaches 77x77 and needs ~512px before it reads
               back reliably. Sizing for the dense case costs nothing. --}}
        <div style="display:flex;justify-content:center;">
            <div style="width:100%;max-width:36rem;border-radius:0.5rem;background:#ffffff;padding:1rem;">
                <img src="{{ $dataUri }}" alt="{{ __('WireGuard configuration QR code') }}" style="display:block;height:auto;width:100%;">
            </div>
        </div>

        <p style="text-align:center;opacity:0.65;">
            {!! __('In the WireGuard app, tap :plus &rarr; :scan, then name the tunnel :name.', [
                'plus' => '<strong>+</strong>',
                'scan' => '<strong>' . e(__('Scan from QR code')) . '</strong>',
                'name' => '<strong>' . e($tunnelName) . '</strong>',
            ]) !!}
        </p>

        <p style="text-align:center;font-size:0.8rem;opacity:0.55;">
            {{ __("This code carries the client's private key -- treat it like the config file itself.") }}
        </p>
    @endif
</div>
