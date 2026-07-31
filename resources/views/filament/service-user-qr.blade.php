<div class="space-y-4">
    @if ($dataUri === null)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ $message }}
        </p>
    @else
        {{-- The QR modules are rendered dark, so the white plate has to be
             explicit: in dark mode the modal background would otherwise sit
             behind them and leave nothing for a camera to read. --}}
        <div class="flex justify-center">
            <div class="rounded-lg bg-white p-4">
                <img src="{{ $dataUri }}" alt="WireGuard configuration QR code" class="h-64 w-64">
            </div>
        </div>

        <p class="text-center text-sm text-gray-500 dark:text-gray-400">
            In the WireGuard app, tap <strong>+</strong> &rarr; <strong>Scan from QR code</strong>,
            then name the tunnel <strong>{{ $tunnelName }}</strong>.
        </p>

        <p class="text-center text-xs text-gray-400 dark:text-gray-500">
            This code carries the client's private key -- treat it like the config file itself.
        </p>
    @endif
</div>
